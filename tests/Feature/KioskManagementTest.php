<?php

namespace Tests\Feature;

use App\Models\AttendanceKiosk;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use App\Models\UserScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Kelola kiosk QR (/api/kiosks): permission kiosk.manage + scope outlet.
 */
class KioskManagementTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $kopi;
    private Outlet $sate;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'PT Maju']);
        $brand = Brand::create(['company_id' => $company->id, 'name' => 'Kopi']);
        $this->kopi = Outlet::create(['brand_id' => $brand->id, 'name' => 'Kopi Sudirman', 'latitude' => -6.2, 'longitude' => 106.8]);
        $this->sate = Outlet::create(['brand_id' => $brand->id, 'name' => 'Sate Menteng']);
    }

    private function user(string $role, ?Outlet $outlet = null): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role . '-' . uniqid() . '@adms.local',
            'password' => Hash::make('password'),
            'role' => $role,
        ]);

        if ($outlet) {
            UserScope::create(['user_id' => $user->id, 'scope_type' => 'outlet', 'scope_id' => $outlet->id]);
        }

        return $user;
    }

    /** kiosk.manage tidak diberikan ke role mana pun secara bawaan; centang untuk manager. */
    private function grantKioskManageToManager(): void
    {
        DB::table('role_permissions')->insert([
            'role_id' => DB::table('roles')->where('key', 'manager')->value('id'),
            'permission' => 'kiosk.manage',
        ]);
        Role::flushCache();
    }

    private function kioskAt(Outlet $outlet, string $name = 'Kasir Depan'): AttendanceKiosk
    {
        return AttendanceKiosk::create(['outlet_id' => $outlet->id, 'name' => $name]);
    }

    public function test_tanpa_kiosk_manage_ditolak(): void
    {
        $kiosk = $this->kioskAt($this->kopi);
        $this->actingAs($this->user('manager', $this->kopi));

        $this->get('/kiosks')->assertForbidden();
        $this->getJson('/api/kiosks')->assertForbidden();
        $this->postJson('/api/kiosks', ['name' => 'X', 'outlet_id' => $this->kopi->id])->assertForbidden();
        $this->postJson("/api/kiosks/{$kiosk->id}/reset")->assertForbidden();
        $this->deleteJson("/api/kiosks/{$kiosk->id}")->assertForbidden();
    }

    public function test_admin_buat_kiosk_dapat_kode_aktivasi_sekali_tampil(): void
    {
        $this->actingAs($this->user('admin'));

        $res = $this->postJson('/api/kiosks', ['name' => 'Kasir Depan', 'outlet_id' => $this->kopi->id])
            ->assertCreated()
            ->assertJsonPath('kiosk.is_activated', false)
            ->assertJsonPath('kiosk.outlet_has_coordinates', true);

        $code = $res->json('activation_code');
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code);

        $kiosk = AttendanceKiosk::firstOrFail();
        $this->assertSame(hash('sha256', $code), $kiosk->activation_code_hash);
        $this->assertTrue($kiosk->activation_expires_at->between(now()->addMinutes(29), now()->addMinutes(31)));

        // Daftar tak pernah membocorkan kode / hash / secret.
        $list = $this->getJson('/api/kiosks')->assertOk()->getContent();
        $this->assertStringNotContainsString($code, $list);
        $this->assertStringNotContainsString($kiosk->activation_code_hash, $list);
        $this->assertStringNotContainsString('secret', $list);

        $this->assertDatabaseHas('audit_logs', ['action' => 'kiosk.created', 'target_id' => $kiosk->id]);
    }

    public function test_manager_hanya_kelola_kiosk_outlet_scope_nya(): void
    {
        $this->grantKioskManageToManager();
        $mine = $this->kioskAt($this->kopi, 'Kiosk Kopi');
        $other = $this->kioskAt($this->sate, 'Kiosk Sate');
        $this->actingAs($this->user('manager', $this->kopi));

        $this->get('/kiosks')->assertOk();
        $this->getJson('/api/kiosks')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $mine->id);

        $this->postJson('/api/kiosks', ['name' => 'Baru', 'outlet_id' => $this->kopi->id])->assertCreated();
        $this->postJson('/api/kiosks', ['name' => 'Nyusup', 'outlet_id' => $this->sate->id])->assertForbidden();

        // Tebak ID kiosk outlet lain -> 403, bukan sekadar disembunyikan.
        $this->putJson("/api/kiosks/{$other->id}", ['name' => 'X'])->assertForbidden();
        $this->postJson("/api/kiosks/{$other->id}/reset")->assertForbidden();
        $this->deleteJson("/api/kiosks/{$other->id}")->assertForbidden();
        $this->assertDatabaseHas('attendance_kiosks', ['id' => $other->id, 'name' => 'Kiosk Sate']);
    }

    public function test_update_nama_dan_nonaktif_tercatat_di_audit(): void
    {
        $kiosk = $this->kioskAt($this->kopi);
        $this->actingAs($this->user('admin'));

        $this->putJson("/api/kiosks/{$kiosk->id}", ['name' => 'Kasir Belakang', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('name', 'Kasir Belakang')
            ->assertJsonPath('is_active', false);

        $audit = AuditLog::where('action', 'kiosk.updated')->firstOrFail();
        $this->assertSame(['from' => 'Kasir Depan', 'to' => 'Kasir Belakang'], $audit->changes['name']);
    }

    public function test_outlet_tidak_bisa_dipindah_lewat_update(): void
    {
        $kiosk = $this->kioskAt($this->kopi);
        $this->actingAs($this->user('admin'));

        $this->putJson("/api/kiosks/{$kiosk->id}", ['outlet_id' => $this->sate->id])->assertOk();

        $this->assertSame($this->kopi->id, $kiosk->fresh()->outlet_id);
    }

    public function test_reset_cabut_token_rotasi_secret_dan_kode_baru(): void
    {
        $kiosk = $this->kioskAt($this->kopi);
        $kiosk->activate();
        $oldSecret = $kiosk->fresh()->secret;
        $this->actingAs($this->user('admin'));

        $code = $this->postJson("/api/kiosks/{$kiosk->id}/reset")
            ->assertOk()
            ->assertJsonPath('kiosk.is_activated', false)
            ->json('activation_code');

        $fresh = $kiosk->fresh();
        $this->assertNull($fresh->device_token_hash);
        $this->assertNotSame($oldSecret, $fresh->secret);
        $this->assertSame(hash('sha256', $code), $fresh->activation_code_hash);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kiosk.reset', 'target_id' => $kiosk->id]);
    }

    public function test_hapus_kiosk_log_tetap_ada(): void
    {
        $kiosk = $this->kioskAt($this->kopi);
        $log = \App\Models\AttendanceLog::create([
            'source' => \App\Models\AttendanceLog::SOURCE_QR,
            'outlet_id' => $this->kopi->id,
            'kiosk_id' => $kiosk->id,
            'biometric_id_lokal' => '1',
            'timestamp' => now(),
            'status_sync' => 'pending_review',
        ]);
        $this->actingAs($this->user('admin'));

        $this->deleteJson("/api/kiosks/{$kiosk->id}")->assertOk();

        $this->assertDatabaseMissing('attendance_kiosks', ['id' => $kiosk->id]);
        $this->assertNull($log->fresh()->kiosk_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kiosk.deleted', 'target_id' => $kiosk->id]);
    }
}
