<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Machine;
use App\Models\Outlet;
use App\Models\User;
use App\Models\UserScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sisa permukaan yang belum tertutup MachineAuthorizationTest &
 * EmployeeAuthorizationTest: sidik jari, organisasi, setting, user, log,
 * dan halaman-halaman Inertia.
 */
class ScopedAccessTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $kopi;
    private Outlet $sate;

    protected function setUp(): void
    {
        parent::setUp();

        $maju = Company::create(['name' => 'PT Maju']);
        $lain = Company::create(['name' => 'PT Lain']);

        $this->kopi = Outlet::create([
            'brand_id' => Brand::create(['company_id' => $maju->id, 'name' => 'Kopi'])->id,
            'name' => 'Kopi Sudirman',
        ]);
        $this->sate = Outlet::create([
            'brand_id' => Brand::create(['company_id' => $lain->id, 'name' => 'Sate'])->id,
            'name' => 'Sate Menteng',
        ]);
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
            UserScope::create([
                'user_id' => $user->id,
                'scope_type' => 'outlet',
                'scope_id' => $outlet->id,
            ]);
        }

        return $user;
    }

    private function machineAt(Outlet $outlet, string $serial): Machine
    {
        return Machine::create([
            'serial_number' => $serial,
            'name' => "Mesin {$serial}",
            'outlet_id' => $outlet->id,
            'ip_address' => '192.168.1.10',
        ]);
    }

    // ===== Sidik jari: mesin TUJUAN juga harus dalam wewenang =====

    public function test_tidak_bisa_menyebar_sidik_jari_ke_mesin_outlet_lain(): void
    {
        $sumber = $this->machineAt($this->kopi, 'SN-KOPI');
        $tujuanOrangLain = $this->machineAt($this->sate, 'SN-SATE');

        $this->actingAs($this->user('manager', $this->kopi));

        // Sumber sah, tujuan bukan wewenangnya -> harus ditolak, bukan diteruskan.
        $this->postJson('/api/fingerprint/sync-bulk', [
            'source_machine_id' => $sumber->id,
            'pins' => ['1'],
            'target_machine_ids' => [$tujuanOrangLain->id],
        ])->assertStatus(403);

        $this->assertDatabaseCount('fingerprint_sync_jobs', 0);
    }

    public function test_tidak_bisa_hapus_massal_user_di_mesin_outlet_lain(): void
    {
        $mesinOrangLain = $this->machineAt($this->sate, 'SN-SATE');

        $this->actingAs($this->user('manager', $this->kopi));

        $this->postJson('/api/fingerprint/delete-bulk', [
            'machine_id' => $mesinOrangLain->id,
            'pins' => ['1'],
        ])->assertStatus(403);

        $this->assertDatabaseCount('fingerprint_delete_jobs', 0);
    }

    public function test_operator_tidak_boleh_hapus_massal_sidik_jari(): void
    {
        // fingerprint.delete bukan milik operator (sumbu 1).
        $mesin = $this->machineAt($this->kopi, 'SN-KOPI');

        $this->actingAs($this->user('operator', $this->kopi));

        $this->postJson('/api/fingerprint/delete-bulk', [
            'machine_id' => $mesin->id,
            'pins' => ['1'],
        ])->assertStatus(403);
    }

    public function test_viewer_tidak_boleh_menyebar_sidik_jari(): void
    {
        $sumber = $this->machineAt($this->kopi, 'SN-KOPI');
        $tujuan = $this->machineAt($this->kopi, 'SN-KOPI-2');

        $this->actingAs($this->user('viewer', $this->kopi));

        $this->postJson('/api/fingerprint/sync-bulk', [
            'source_machine_id' => $sumber->id,
            'pins' => ['1'],
            'target_machine_ids' => [$tujuan->id],
        ])->assertStatus(403);
    }

    // ===== Struktur organisasi: hanya admin yang boleh mengubah =====

    public function test_non_admin_tidak_boleh_mengubah_struktur_organisasi(): void
    {
        foreach (['manager', 'operator', 'viewer'] as $role) {
            $this->actingAs($this->user($role, $this->kopi));

            $this->postJson('/api/companies', ['name' => "PT {$role}"])->assertStatus(403);
            $this->deleteJson("/api/outlets/{$this->kopi->id}")->assertStatus(403);
        }

        $this->assertDatabaseHas('outlets', ['id' => $this->kopi->id]);
    }

    public function test_pohon_organisasi_hanya_menampilkan_cabang_dalam_scope(): void
    {
        $this->actingAs($this->user('manager', $this->kopi));

        $this->getJson('/api/companies')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'PT Maju'])
            // Nama perusahaan & outlet lain tak boleh bocor lewat dropdown.
            ->assertJsonMissing(['name' => 'PT Lain'])
            ->assertJsonMissing(['name' => 'Sate Menteng']);
    }

    // ===== Setting & user: admin-only =====

    public function test_non_admin_tidak_boleh_mengubah_setting(): void
    {
        $this->actingAs($this->user('manager', $this->kopi));

        $this->putJson('/api/settings', ['settings' => ['talenta_client_id' => 'bocor']])
            ->assertStatus(403);
    }

    public function test_non_admin_tidak_boleh_membuka_halaman_setting(): void
    {
        $this->actingAs($this->user('manager', $this->kopi));

        // Halaman memuat daftar seluruh user; menyembunyikan menu saja tak cukup.
        $this->get('/settings')->assertStatus(403);
    }

    public function test_admin_bisa_menugaskan_scope_saat_membuat_user(): void
    {
        $this->actingAs($this->user('admin'));

        $this->postJson('/api/users', [
            'name' => 'Manajer Kopi',
            'email' => 'kopi@adms.local',
            'password' => 'rahasia123',
            'role' => 'manager',
            'outlet_ids' => [$this->kopi->id],
        ])->assertCreated()
            ->assertJsonFragment(['outlet_ids' => [$this->kopi->id]]);

        $baru = User::where('email', 'kopi@adms.local')->first();

        $this->assertSame([$this->kopi->id], $baru->scopedOutletIds());
    }

    public function test_scope_admin_tidak_disimpan(): void
    {
        // Admin tak pernah dibatasi outlet; menyimpan barisnya cuma menyesatkan.
        $this->actingAs($this->user('admin'));

        $this->postJson('/api/users', [
            'name' => 'Admin Dua',
            'email' => 'admin2@adms.local',
            'password' => 'rahasia123',
            'role' => 'admin',
            'outlet_ids' => [$this->kopi->id],
        ])->assertCreated();

        $baru = User::where('email', 'admin2@adms.local')->first();

        $this->assertSame(0, UserScope::where('user_id', $baru->id)->count());
        $this->assertNull($baru->scopedOutletIds());
    }

    // ===== Log absensi =====

    public function test_kirim_massal_hanya_menyentuh_log_dalam_scope(): void
    {
        $logKopi = AttendanceLog::create([
            'machine_id' => $this->machineAt($this->kopi, 'SN-KOPI')->id,
            'biometric_id_lokal' => '1',
            'timestamp' => now(),
            'status_sync' => 'pending',
        ]);
        $logSate = AttendanceLog::create([
            'machine_id' => $this->machineAt($this->sate, 'SN-SATE')->id,
            'biometric_id_lokal' => '2',
            'timestamp' => now(),
            'status_sync' => 'pending',
        ]);

        // Kedua PIN tak terhubung karyawan -> service menandainya 'failed'.
        // Yang diuji: log outlet LAIN tak boleh ikut tersentuh sama sekali.
        $this->actingAs($this->user('operator', $this->kopi));
        $this->postJson('/api/attendance-logs/send-pending')->assertOk();

        $this->assertSame('failed', $logKopi->fresh()->status_sync);
        $this->assertSame('pending', $logSate->fresh()->status_sync, 'Log outlet lain tak boleh tersentuh.');
    }

    public function test_tidak_bisa_mengirim_log_outlet_lain_dengan_menebak_id(): void
    {
        $logSate = AttendanceLog::create([
            'machine_id' => $this->machineAt($this->sate, 'SN-SATE')->id,
            'biometric_id_lokal' => '2',
            'timestamp' => now(),
            'status_sync' => 'pending',
        ]);

        $this->actingAs($this->user('operator', $this->kopi));

        $this->postJson("/api/attendance-logs/{$logSate->id}/send")->assertStatus(403);
        $this->assertSame('pending', $logSate->fresh()->status_sync);
    }

    public function test_viewer_tidak_boleh_mengirim_log(): void
    {
        $this->actingAs($this->user('viewer', $this->kopi));

        $this->postJson('/api/attendance-logs/send-pending')->assertStatus(403);
        $this->postJson('/api/attendance-logs/send-failed')->assertStatus(403);
    }

    // ===== Halaman Inertia ikut disaring =====

    public function test_halaman_mesin_hanya_menampilkan_outlet_dalam_scope(): void
    {
        $this->machineAt($this->kopi, 'SN-KOPI');
        $this->machineAt($this->sate, 'SN-SATE');

        $this->actingAs($this->user('manager', $this->kopi));

        $this->get('/machines')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Machines')
                ->has('machines', 1)
                ->where('machines.0.serial_number', 'SN-KOPI'));
    }

    public function test_halaman_karyawan_hanya_menampilkan_outlet_dalam_scope(): void
    {
        $andi = Employee::create(['name' => 'Andi', 'talenta_employee_id' => 'T1']);
        $andi->outlets()->sync([$this->kopi->id]);

        $budi = Employee::create(['name' => 'Budi', 'talenta_employee_id' => 'T2']);
        $budi->outlets()->sync([$this->sate->id]);

        $this->actingAs($this->user('manager', $this->kopi));

        $this->get('/employee-management')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('EmployeeManagement')
                ->has('employees', 1)
                ->where('employees.0.name', 'Andi'));
    }

    public function test_permission_dikirim_ke_frontend_untuk_menyembunyikan_menu(): void
    {
        $this->actingAs($this->user('viewer', $this->kopi));

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.permissions', fn ($perms) => collect($perms)->contains('machine.view')
                    // Viewer tak boleh melihat menu Pengaturan maupun tombol hapus.
                    && ! collect($perms)->contains('setting.manage')
                    && ! collect($perms)->contains('machine.manage'))
                ->etc());
    }

    public function test_dashboard_hanya_menghitung_log_dalam_scope(): void
    {
        AttendanceLog::create([
            'machine_id' => $this->machineAt($this->kopi, 'SN-KOPI')->id,
            'biometric_id_lokal' => '1',
            'timestamp' => now(),
            'status_sync' => 'pending',
        ]);
        AttendanceLog::create([
            'machine_id' => $this->machineAt($this->sate, 'SN-SATE')->id,
            'biometric_id_lokal' => '2',
            'timestamp' => now(),
            'status_sync' => 'pending',
        ]);

        $this->actingAs($this->user('manager', $this->kopi));

        $this->getJson('/api/dashboard-stats')
            ->assertOk()
            ->assertJsonFragment(['queue_pending' => 1]);
    }
}
