<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Machine;
use App\Models\Outlet;
use App\Models\User;
use App\Models\UserScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Endpoint /api/machines: dua sumbu izin diuji lewat HTTP sungguhan.
 * Yang paling penting di sini adalah uji "tebak ID": data di luar scope tidak
 * cukup hanya disembunyikan dari daftar, mengaksesnya langsung harus 403.
 */
class MachineAuthorizationTest extends TestCase
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

    private function machineAt(?Outlet $outlet, string $serial): Machine
    {
        return Machine::create([
            'serial_number' => $serial,
            'name' => "Mesin {$serial}",
            'outlet_id' => $outlet?->id,
        ]);
    }

    public function test_daftar_mesin_hanya_berisi_outlet_dalam_scope(): void
    {
        $this->machineAt($this->kopi, 'SN-KOPI');
        $this->machineAt($this->sate, 'SN-SATE');
        $this->machineAt(null, 'SN-YATIM');

        $this->actingAs($this->user('manager', $this->kopi));

        $this->getJson('/api/machines')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['serial_number' => 'SN-KOPI'])
            ->assertJsonMissing(['serial_number' => 'SN-SATE'])
            ->assertJsonMissing(['serial_number' => 'SN-YATIM']);
    }

    public function test_admin_melihat_semua_mesin_termasuk_yang_belum_ditempatkan(): void
    {
        $this->machineAt($this->kopi, 'SN-KOPI');
        $this->machineAt($this->sate, 'SN-SATE');
        $this->machineAt(null, 'SN-YATIM');

        $this->actingAs($this->user('admin'));

        $this->getJson('/api/machines')->assertOk()->assertJsonCount(3);
    }

    public function test_menebak_id_mesin_outlet_lain_tetap_ditolak(): void
    {
        $mesinOrangLain = $this->machineAt($this->sate, 'SN-SATE');

        $this->actingAs($this->user('admin'));
        $this->getJson("/api/machines/{$mesinOrangLain->id}/zk-info")->assertOk();

        // Manager Kopi tahu ID-nya, tapi tetap tak boleh mengintip.
        $this->actingAs($this->user('manager', $this->kopi));
        $this->getJson("/api/machines/{$mesinOrangLain->id}/zk-info")->assertStatus(403);
    }

    public function test_manager_tidak_boleh_mengubah_mesin_meski_di_outletnya(): void
    {
        // Manager tak punya permission machine.manage sama sekali (sumbu 1).
        $mesin = $this->machineAt($this->kopi, 'SN-KOPI');

        $this->actingAs($this->user('manager', $this->kopi));

        $this->putJson("/api/machines/{$mesin->id}", [
            'serial_number' => 'SN-KOPI',
            'name' => 'Diubah',
        ])->assertStatus(403);

        $this->deleteJson("/api/machines/{$mesin->id}")->assertStatus(403);
        $this->postJson('/api/machines', ['serial_number' => 'SN-BARU', 'name' => 'Baru'])
            ->assertStatus(403);
    }

    public function test_viewer_tidak_boleh_menghapus_mesin(): void
    {
        $mesin = $this->machineAt($this->kopi, 'SN-KOPI');

        $this->actingAs($this->user('viewer', $this->kopi));

        $this->deleteJson("/api/machines/{$mesin->id}")->assertStatus(403);
        $this->assertDatabaseHas('machines', ['id' => $mesin->id]);
    }

    public function test_admin_boleh_membuat_dan_menghapus_mesin(): void
    {
        $this->actingAs($this->user('admin'));

        // Laravel mengembalikan 201 untuk model yang baru dibuat.
        $this->postJson('/api/machines', [
            'serial_number' => 'SN-BARU',
            'name' => 'Mesin Baru',
            'outlet_id' => $this->kopi->id,
        ])->assertCreated();

        $this->assertDatabaseHas('machines', ['serial_number' => 'SN-BARU', 'outlet_id' => $this->kopi->id]);

        $mesin = Machine::where('serial_number', 'SN-BARU')->first();
        $this->deleteJson("/api/machines/{$mesin->id}")->assertOk();
        $this->assertDatabaseMissing('machines', ['id' => $mesin->id]);
    }

    public function test_operator_tidak_boleh_menghapus_user_dari_mesin(): void
    {
        // fingerprint.delete tidak dimiliki operator.
        $mesin = $this->machineAt($this->kopi, 'SN-KOPI');

        $this->actingAs($this->user('operator', $this->kopi));

        $this->deleteJson("/api/machines/{$mesin->id}/zk-users/123")->assertStatus(403);
    }

    public function test_viewer_tidak_boleh_menghapus_log_di_perangkat(): void
    {
        $mesin = $this->machineAt($this->kopi, 'SN-KOPI');

        $this->actingAs($this->user('viewer', $this->kopi));

        $this->postJson("/api/machines/{$mesin->id}/clear-attendance")->assertStatus(403);
    }
}
