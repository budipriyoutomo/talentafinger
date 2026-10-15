<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Machine;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use App\Models\UserScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Role dinamis (tabel roles), role global data.all, dan pagar pendelegasian. */
class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $maju;
    private Outlet $kopi;
    private Outlet $sate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maju = Company::create(['name' => 'PT Maju']);
        $lain = Company::create(['name' => 'PT Lain']);

        $this->kopi = Outlet::create([
            'brand_id' => Brand::create(['company_id' => $this->maju->id, 'name' => 'Kopi'])->id,
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
            'email' => $role.'-'.uniqid().'@adms.local',
            'password' => Hash::make('password'),
            'role' => $role,
        ]);

        if ($outlet) {
            UserScope::create(['user_id' => $user->id, 'scope_type' => 'outlet', 'scope_id' => $outlet->id]);
        }

        return $user;
    }

    private function role(string $key, array $permissions): Role
    {
        $role = Role::create(['key' => $key, 'name' => ucfirst($key)]);
        $role->syncPermissions($permissions);

        return $role;
    }

    private function grant(string $roleKey, string ...$permissions): void
    {
        $role = Role::where('key', $roleKey)->first();
        $role->syncPermissions([...$role->permissions(), ...$permissions]);
    }

    // ---------------------------------------------------------------- role CRUD

    public function test_default_roles_are_seeded_by_migration(): void
    {
        $this->assertEqualsCanonicalizing(
            ['admin', 'manager', 'operator', 'viewer', 'employee'],
            Role::pluck('key')->all(),
        );
        $this->assertTrue($this->user('manager')->hasPermission('employee.manage'));
        $this->assertFalse($this->user('viewer')->hasPermission('employee.manage'));
    }

    public function test_admin_can_create_update_and_delete_role(): void
    {
        $this->actingAs($this->user('admin'));

        $id = $this->postJson('/api/roles', [
            'key' => 'supervisor', 'name' => 'Supervisor', 'permissions' => ['machine.view', 'attendance.view'],
        ])->assertStatus(201)->json('id');

        $permissions = $this->putJson("/api/roles/{$id}", [
            'name' => 'Supervisor Outlet', 'permissions' => ['attendance.view', 'attendance.review'],
        ])->assertOk()->json('permissions');
        $this->assertEqualsCanonicalizing(['attendance.view', 'attendance.review'], $permissions);

        // Perubahan permission langsung berlaku (cache dibuang).
        // (User tak disimpan: role yang masih dipakai tak bisa dihapus di bawah.)
        $this->assertTrue((new User(['role' => 'supervisor']))->hasPermission('attendance.review'));
        $this->assertFalse((new User(['role' => 'supervisor']))->hasPermission('machine.view'));

        $this->deleteJson("/api/roles/{$id}")->assertOk();
        $this->assertNull(Role::find($id));
    }

    public function test_role_key_and_permissions_are_validated(): void
    {
        $this->actingAs($this->user('admin'));

        $this->postJson('/api/roles', ['key' => 'Bad Key', 'name' => 'X', 'permissions' => []])
            ->assertStatus(422)->assertJsonValidationErrors('key');
        $this->postJson('/api/roles', ['key' => 'manager', 'name' => 'X', 'permissions' => []])
            ->assertStatus(422)->assertJsonValidationErrors('key');
        // Absen foto hanya untuk role sistem `employee`; '*' tak bisa dicentang.
        $this->postJson('/api/roles', ['key' => 'x', 'name' => 'X', 'permissions' => ['attendance.manual.create']])
            ->assertStatus(422);
        $this->postJson('/api/roles', ['key' => 'x', 'name' => 'X', 'permissions' => ['*']])
            ->assertStatus(422);
    }

    public function test_system_roles_are_locked_and_used_roles_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('admin'));
        $admin = Role::where('key', 'admin')->first();
        $employee = Role::where('key', 'employee')->first();

        $this->putJson("/api/roles/{$admin->id}", ['name' => 'X', 'permissions' => []])->assertStatus(403);
        $this->deleteJson("/api/roles/{$employee->id}")->assertStatus(403);

        $this->user('viewer');
        $viewer = Role::where('key', 'viewer')->first();
        $this->deleteJson("/api/roles/{$viewer->id}")->assertStatus(422);
    }

    public function test_role_management_requires_permission(): void
    {
        $this->actingAs($this->user('manager', $this->kopi));

        $this->getJson('/api/roles')->assertStatus(403);
    }

    public function test_non_admin_role_manager_cannot_grant_more_than_it_has_or_edit_own_role(): void
    {
        $this->grant('manager', 'role.manage');
        $this->actingAs($this->user('manager', $this->kopi));

        $this->postJson('/api/roles', [
            'key' => 'helper', 'name' => 'Helper', 'permissions' => ['employee.view'],
        ])->assertStatus(201);

        $this->postJson('/api/roles', [
            'key' => 'naik', 'name' => 'Naik', 'permissions' => ['machine.manage'],
        ])->assertStatus(403);

        $own = Role::where('key', 'manager')->first();
        $this->putJson("/api/roles/{$own->id}", [
            'name' => 'Manager', 'permissions' => $own->permissions(),
        ])->assertStatus(403);
    }

    // ------------------------------------------------------- role global data.all

    public function test_data_all_role_sees_every_outlet_without_scope(): void
    {
        $this->role('hr_pusat', ['machine.view', 'data.all']);
        Machine::create(['serial_number' => 'K1', 'name' => 'Kopi', 'outlet_id' => $this->kopi->id]);
        Machine::create(['serial_number' => 'S1', 'name' => 'Sate', 'outlet_id' => $this->sate->id]);

        $hr = $this->user('hr_pusat');
        $this->assertTrue($hr->hasFullDataAccess());
        $this->assertNull($hr->scopedOutletIds());

        $this->actingAs($hr);
        $this->assertCount(2, Machine::visibleTo($hr)->get());
        // Tetap bukan admin: aksi di luar permission-nya ditolak.
        $this->postJson('/api/machines', ['serial_number' => 'X1', 'name' => 'X'])->assertStatus(403);
    }

    // --------------------------------------------------- pendelegasian user/scope

    public function test_non_admin_user_manager_can_only_assign_its_own_scope(): void
    {
        $this->grant('manager', 'user.manage');
        $this->actingAs($this->user('manager', $this->kopi));

        $base = ['password' => 'password123', 'role' => 'operator'];

        $this->postJson('/api/users', [...$base, 'name' => 'A', 'email' => 'a@adms.local', 'outlet_ids' => [$this->kopi->id]])
            ->assertStatus(201);
        $this->postJson('/api/users', [...$base, 'name' => 'B', 'email' => 'b@adms.local', 'outlet_ids' => [$this->sate->id]])
            ->assertStatus(403);
        // Company induk outlet-nya pun tak boleh: kelak outlet baru ikut terbawa.
        $this->postJson('/api/users', [...$base, 'name' => 'C', 'email' => 'c@adms.local', 'company_ids' => [$this->maju->id]])
            ->assertStatus(403);
    }

    public function test_non_admin_user_manager_cannot_assign_stronger_role(): void
    {
        $this->role('teknisi', ['machine.view', 'machine.manage']);
        $this->grant('manager', 'user.manage');
        $this->actingAs($this->user('manager', $this->kopi));

        $this->postJson('/api/users', [
            'name' => 'T', 'email' => 't@adms.local', 'password' => 'password123', 'role' => 'teknisi',
        ])->assertStatus(403);
    }

    public function test_non_admin_user_manager_only_sees_and_edits_users_within_its_reach(): void
    {
        $this->grant('manager', 'user.manage');
        $mine = $this->user('operator', $this->kopi);
        $theirs = $this->user('operator', $this->sate);
        $this->actingAs($this->user('manager', $this->kopi));

        $ids = collect($this->getJson('/api/users')->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));

        $this->putJson("/api/users/{$theirs->id}", [
            'name' => 'X', 'email' => $theirs->email, 'role' => 'viewer',
        ])->assertStatus(403);
        $this->deleteJson("/api/users/{$theirs->id}")->assertStatus(403);
    }

    public function test_non_admin_user_manager_cannot_link_employee_outside_scope(): void
    {
        $this->grant('manager', 'user.manage');
        $outsider = Employee::create(['name' => 'Joko', 'biometric_id' => '77']);
        $outsider->outlets()->attach($this->sate->id);
        $this->actingAs($this->user('manager', $this->kopi));

        $this->postJson('/api/users', [
            'name' => 'Joko', 'email' => 'joko@adms.local', 'password' => 'password123',
            'role' => 'employee', 'employee_id' => $outsider->id,
        ])->assertStatus(403);
    }
}
