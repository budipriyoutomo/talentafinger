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
 * Fondasi izin dua sumbu: PERMISSION (boleh aksi apa) + SCOPE (boleh data mana).
 * Belum menguji endpoint HTTP — itu menyusul saat rute dipasangi otorisasi.
 */
class PermissionScopeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, ?string $email = null): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $email ?: "{$role}-" . uniqid() . '@adms.local',
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    /** Bangun Company > Brand > Outlet sekali pakai. */
    private function outlet(string $company, string $brand, string $outlet): Outlet
    {
        $c = Company::firstOrCreate(['name' => $company]);
        $b = Brand::firstOrCreate(['company_id' => $c->id, 'name' => $brand]);

        return Outlet::create(['brand_id' => $b->id, 'name' => $outlet]);
    }

    private function assign(User $user, string $type, string $id): void
    {
        UserScope::create(['user_id' => $user->id, 'scope_type' => $type, 'scope_id' => $id]);
    }

    private function machineAt(?Outlet $outlet, string $serial): Machine
    {
        return Machine::create([
            'serial_number' => $serial,
            'name' => "Mesin {$serial}",
            'outlet_id' => $outlet?->id,
        ]);
    }

    // ===== Sumbu 1: permission (verb) =====

    public function test_admin_punya_semua_permission(): void
    {
        $admin = $this->user('admin');

        foreach (array_keys(config('permissions.permissions')) as $permission) {
            $this->assertTrue($admin->hasPermission($permission), "admin seharusnya boleh {$permission}");
        }
    }

    public function test_viewer_hanya_boleh_melihat(): void
    {
        $viewer = $this->user('viewer');

        $this->assertTrue($viewer->hasPermission('machine.view'));
        $this->assertFalse($viewer->hasPermission('machine.manage'));
        $this->assertFalse($viewer->hasPermission('attendance.send'));
        $this->assertFalse($viewer->hasPermission('fingerprint.delete'));
    }

    public function test_hanya_admin_yang_boleh_kelola_user_setting_dan_organisasi(): void
    {
        foreach (['manager', 'operator', 'viewer'] as $role) {
            $user = $this->user($role);

            $this->assertFalse($user->hasPermission('user.manage'), "{$role} tak boleh kelola user");
            $this->assertFalse($user->hasPermission('setting.manage'), "{$role} tak boleh ubah setting");
            $this->assertFalse($user->hasPermission('org.manage'), "{$role} tak boleh ubah organisasi");
        }
    }

    public function test_operator_tidak_boleh_menghapus_sidik_jari_tapi_manager_boleh(): void
    {
        $this->assertFalse($this->user('operator')->hasPermission('fingerprint.delete'));
        $this->assertTrue($this->user('manager')->hasPermission('fingerprint.delete'));
    }

    // ===== Sumbu 2: scope (baris) =====

    public function test_scope_company_menurun_ke_semua_outlet_di_bawahnya(): void
    {
        $a1 = $this->outlet('PT Maju', 'Kopi', 'Kopi Sudirman');
        $a2 = $this->outlet('PT Maju', 'Kopi', 'Kopi Thamrin');
        $a3 = $this->outlet('PT Maju', 'Bakmi', 'Bakmi Kelapa Gading');
        $lain = $this->outlet('PT Lain', 'Sate', 'Sate Menteng');

        $user = $this->user('manager');
        $this->assign($user, 'company', $a1->brand->company_id);

        $scope = $user->scopedOutletIds();

        $this->assertEqualsCanonicalizing([$a1->id, $a2->id, $a3->id], $scope);
        $this->assertNotContains($lain->id, $scope);
    }

    public function test_scope_brand_hanya_mencakup_outlet_brand_itu(): void
    {
        $kopi = $this->outlet('PT Maju', 'Kopi', 'Kopi Sudirman');
        $bakmi = $this->outlet('PT Maju', 'Bakmi', 'Bakmi Kelapa Gading');

        $user = $this->user('manager');
        $this->assign($user, 'brand', $kopi->brand_id);

        $this->assertSame([$kopi->id], $user->scopedOutletIds());
        $this->assertFalse($user->canAccessOutlet($bakmi->id));
    }

    public function test_admin_tanpa_batas_outlet(): void
    {
        $this->outlet('PT Maju', 'Kopi', 'Kopi Sudirman');

        // null = tanpa batas, bukan "tidak punya outlet".
        $this->assertNull($this->user('admin')->scopedOutletIds());
        $this->assertTrue($this->user('admin')->canAccessOutlet(null));
    }

    public function test_user_tanpa_penugasan_tidak_melihat_apa_pun(): void
    {
        $outlet = $this->outlet('PT Maju', 'Kopi', 'Kopi Sudirman');
        $user = $this->user('viewer');

        $this->assertSame([], $user->scopedOutletIds());
        $this->assertFalse($user->canAccessOutlet($outlet->id));
    }

    // ===== Penyaringan query =====

    public function test_mesin_di_luar_scope_tidak_terlihat(): void
    {
        $kopi = $this->outlet('PT Maju', 'Kopi', 'Kopi Sudirman');
        $sate = $this->outlet('PT Lain', 'Sate', 'Sate Menteng');

        $milikku = $this->machineAt($kopi, 'SN-KOPI');
        $this->machineAt($sate, 'SN-SATE');

        $user = $this->user('manager');
        $this->assign($user, 'outlet', $kopi->id);

        $terlihat = Machine::visibleTo($user)->pluck('id')->all();

        $this->assertSame([$milikku->id], $terlihat);
    }

    public function test_mesin_belum_ditempatkan_hanya_terlihat_admin(): void
    {
        $kopi = $this->outlet('PT Maju', 'Kopi', 'Kopi Sudirman');
        $yatim = $this->machineAt(null, 'SN-BELUM-DITEMPATKAN');

        $manager = $this->user('manager');
        $this->assign($manager, 'outlet', $kopi->id);

        $this->assertNotContains($yatim->id, Machine::visibleTo($manager)->pluck('id')->all());
        $this->assertContains($yatim->id, Machine::visibleTo($this->user('admin'))->pluck('id')->all());
    }

    public function test_log_mengikuti_outlet_mesinnya(): void
    {
        $kopi = $this->outlet('PT Maju', 'Kopi', 'Kopi Sudirman');
        $sate = $this->outlet('PT Lain', 'Sate', 'Sate Menteng');

        $logKopi = AttendanceLog::create([
            'machine_id' => $this->machineAt($kopi, 'SN-KOPI')->id,
            'biometric_id_lokal' => '1',
            'timestamp' => now(),
            'status_sync' => 'pending',
        ]);
        AttendanceLog::create([
            'machine_id' => $this->machineAt($sate, 'SN-SATE')->id,
            'biometric_id_lokal' => '2',
            'timestamp' => now(),
            'status_sync' => 'pending',
        ]);

        $user = $this->user('operator');
        $this->assign($user, 'outlet', $kopi->id);

        $this->assertSame([$logKopi->id], AttendanceLog::visibleTo($user)->pluck('id')->all());
        $this->assertCount(2, AttendanceLog::visibleTo($this->user('admin'))->get());
    }

    public function test_karyawan_terlihat_bila_salah_satu_outletnya_masuk_scope(): void
    {
        $kopi = $this->outlet('PT Maju', 'Kopi', 'Kopi Sudirman');
        $sate = $this->outlet('PT Lain', 'Sate', 'Sate Menteng');

        // Karyawan lintas-outlet: satu kakinya di dalam scope.
        $lintas = Employee::create(['name' => 'Lintas', 'talenta_employee_id' => 'T1']);
        $lintas->outlets()->sync([$kopi->id, $sate->id]);

        $luar = Employee::create(['name' => 'Luar', 'talenta_employee_id' => 'T2']);
        $luar->outlets()->sync([$sate->id]);

        $yatim = Employee::create(['name' => 'Tanpa Outlet', 'talenta_employee_id' => 'T3']);

        $user = $this->user('manager');
        $this->assign($user, 'outlet', $kopi->id);

        $terlihat = Employee::visibleTo($user)->pluck('id')->all();

        $this->assertSame([$lintas->id], $terlihat);
        $this->assertNotContains($luar->id, $terlihat);
        $this->assertNotContains($yatim->id, $terlihat);
    }

    // ===== Policy: dua sumbu digabung =====

    public function test_policy_menolak_mesin_di_luar_scope_meski_permissionnya_ada(): void
    {
        $kopi = $this->outlet('PT Maju', 'Kopi', 'Kopi Sudirman');
        $sate = $this->outlet('PT Lain', 'Sate', 'Sate Menteng');

        $admin = $this->user('admin');
        $mesinLuar = $this->machineAt($sate, 'SN-SATE');
        $mesinKopi = $this->machineAt($kopi, 'SN-KOPI');

        // Manager TIDAK punya machine.manage sama sekali -> ditolak di sumbu 1.
        $manager = $this->user('manager');
        $this->assign($manager, 'outlet', $kopi->id);
        $this->assertFalse($manager->can('update', $mesinKopi));
        $this->assertTrue($manager->can('view', $mesinKopi));
        // ...dan mesin di luar scope tak boleh dilihat sekalipun -> sumbu 2.
        $this->assertFalse($manager->can('view', $mesinLuar));

        // Admin lolos keduanya.
        $this->assertTrue($admin->can('update', $mesinLuar));
    }

    public function test_policy_menolak_karyawan_di_luar_scope(): void
    {
        $kopi = $this->outlet('PT Maju', 'Kopi', 'Kopi Sudirman');
        $sate = $this->outlet('PT Lain', 'Sate', 'Sate Menteng');

        $milikku = Employee::create(['name' => 'Milikku', 'talenta_employee_id' => 'T1']);
        $milikku->outlets()->sync([$kopi->id]);

        $bukanMilikku = Employee::create(['name' => 'Bukan', 'talenta_employee_id' => 'T2']);
        $bukanMilikku->outlets()->sync([$sate->id]);

        $manager = $this->user('manager');
        $this->assign($manager, 'outlet', $kopi->id);

        $this->assertTrue($manager->can('update', $milikku));
        $this->assertFalse($manager->can('update', $bukanMilikku));

        // Operator boleh lihat tapi tak boleh ubah (sumbu 1).
        $operator = $this->user('operator');
        $this->assign($operator, 'outlet', $kopi->id);
        $this->assertTrue($operator->can('view', $milikku));
        $this->assertFalse($operator->can('update', $milikku));
    }

    // ===== Kebersihan data =====

    public function test_penugasan_ikut_terhapus_saat_organisasi_dihapus(): void
    {
        $outlet = $this->outlet('PT Maju', 'Kopi', 'Kopi Sudirman');
        $company = $outlet->brand->company;

        $user = $this->user('manager');
        $this->assign($user, 'outlet', $outlet->id);
        $this->assign($user, 'brand', $outlet->brand_id);
        $this->assign($user, 'company', $company->id);

        $this->assertSame(3, UserScope::where('user_id', $user->id)->count());

        // Hapus company -> brand & outlet ikut lenyap via FK cascade; ketiga
        // penugasan tak boleh meninggalkan baris yatim.
        $company->delete();

        $this->assertSame(0, UserScope::where('user_id', $user->id)->count());
    }
}
