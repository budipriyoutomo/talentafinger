<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use App\Models\UserScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Buat akun role Karyawan dari data karyawan (POST /api/users/from-employees). */
class UserFromEmployeesTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Awal#2026abc';

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'PT Uji']);
        $brand = Brand::create(['company_id' => $company->id, 'name' => 'Brand Uji']);
        $this->outlet = Outlet::create(['brand_id' => $brand->id, 'name' => 'Outlet Uji']);
    }

    private function user(string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    private function employee(string $name, ?string $email, bool $active = true, ?Outlet $outlet = null): Employee
    {
        $employee = Employee::create(['name' => $name, 'email' => $email, 'is_active' => $active]);
        $employee->outlets()->sync([($outlet ?? $this->outlet)->id]);

        return $employee;
    }

    private function createAccounts(User $actor, array $employees)
    {
        return $this->actingAs($actor)->postJson('/api/users/from-employees', [
            'employee_ids' => collect($employees)->pluck('id')->all(),
            'password' => self::PASSWORD,
        ]);
    }

    public function test_membuat_akun_karyawan_dan_melewati_yang_tak_memenuhi_syarat(): void
    {
        $admin = $this->user('admin', 'admin@adms.local');
        $siti = $this->employee('Siti', ' Siti@Contoh.COM ');
        $tanpaEmail = $this->employee('Tanpa Email', null);
        $nonaktif = $this->employee('Nonaktif', 'off@contoh.com', active: false);
        $sudahAda = $this->employee('Sudah Ada', 'ada@contoh.com');
        User::create(['name' => 'Sudah Ada', 'email' => 'ada@contoh.com', 'password' => 'x', 'role' => Role::EMPLOYEE, 'employee_id' => $sudahAda->id]);
        $bentrok = $this->employee('Bentrok', 'admin@adms.local');

        $this->createAccounts($admin, [$siti, $tanpaEmail, $nonaktif, $sudahAda, $bentrok])
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonCount(4, 'skipped');

        $user = User::where('employee_id', $siti->id)->sole();
        $this->assertSame('siti@contoh.com', $user->email);
        $this->assertSame(Role::EMPLOYEE, $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    public function test_email_kembar_dalam_satu_batch_hanya_dibuat_sekali(): void
    {
        $admin = $this->user('admin', 'admin@adms.local');
        $a = $this->employee('A', 'sama@contoh.com');
        $b = $this->employee('B', 'sama@contoh.com');

        $this->createAccounts($admin, [$a, $b])->assertOk()->assertJsonPath('created', 1);
        $this->assertSame(1, User::where('email', 'sama@contoh.com')->count());
    }

    public function test_manager_hanya_untuk_karyawan_di_wewenangnya(): void
    {
        $manager = $this->user('manager', 'manager@adms.local');
        $manager->roleModel->syncPermissions([...$manager->permissions(), 'user.manage']);
        UserScope::create(['user_id' => $manager->id, 'scope_type' => 'outlet', 'scope_id' => $this->outlet->id]);

        $other = Outlet::create(['brand_id' => $this->outlet->brand_id, 'name' => 'Outlet Lain']);
        $milik = $this->employee('Milik', 'milik@contoh.com');
        $luar = $this->employee('Luar', 'luar@contoh.com', outlet: $other);

        $this->createAccounts($manager->fresh(), [$milik, $luar])->assertOk()->assertJsonPath('created', 1);
        $this->assertFalse(User::where('employee_id', $luar->id)->exists());
    }

    public function test_tanpa_user_manage_ditolak(): void
    {
        $operator = $this->user('operator', 'op@adms.local');

        $this->createAccounts($operator, [$this->employee('Siti', 'siti@contoh.com')])->assertForbidden();
        $this->assertSame(1, User::count());
    }
}
