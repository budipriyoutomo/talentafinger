<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => "{$role}@adms.local",
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    public function test_non_admin_cannot_list_users(): void
    {
        $this->actingAs($this->user('operator'));

        $this->getJson('/api/users')->assertStatus(403);
    }

    public function test_admin_can_list_users(): void
    {
        $this->actingAs($this->user('admin'));

        $this->getJson('/api/users')->assertStatus(200);
    }

    public function test_non_admin_cannot_create_user(): void
    {
        $this->actingAs($this->user('viewer'));

        $this->postJson('/api/users', [
            'name' => 'X',
            'email' => 'x@adms.local',
            'password' => 'password123',
            'role' => 'operator',
        ])->assertStatus(403);
    }

    /** Beri role manager izin user.manage (simulasi role dinamis kelak). */
    private function managerWithUserManage(): User
    {
        $role = Role::where('key', 'manager')->first();
        $role->syncPermissions([...$role->permissions(), 'user.manage']);

        return $this->user('manager');
    }

    public function test_user_management_is_gated_by_permission_not_role(): void
    {
        $this->actingAs($this->managerWithUserManage());

        $this->getJson('/api/users')->assertStatus(200);
        $this->postJson('/api/users', [
            'name' => 'Op', 'email' => 'op@adms.local', 'password' => 'password123', 'role' => 'operator',
        ])->assertStatus(201);
    }

    public function test_non_admin_user_manager_cannot_touch_admin_accounts(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($this->managerWithUserManage());

        // Membuat admin baru = eskalasi.
        $this->postJson('/api/users', [
            'name' => 'Naik', 'email' => 'naik@adms.local', 'password' => 'password123', 'role' => 'admin',
        ])->assertStatus(403);

        $this->putJson("/api/users/{$admin->id}", [
            'name' => 'Admin', 'email' => 'admin@adms.local', 'role' => 'viewer',
        ])->assertStatus(403);

        $this->deleteJson("/api/users/{$admin->id}")->assertStatus(403);
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_settings_page_sends_each_tab_only_with_its_permission(): void
    {
        $this->actingAs($this->managerWithUserManage());

        $this->get('/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('groups', [])
                ->has('users')
                ->where('roles', fn ($roles) => ! collect($roles)->firstWhere('key', 'admin')['assignable'])
                ->where('permissionCatalog', []));

        $this->actingAs($this->user('operator'));
        $this->get('/settings')->assertStatus(403);
    }

    public function test_cannot_delete_last_admin(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('operator');
        $this->actingAs($admin);

        // Hanya ada 1 admin -> menghapusnya harus ditolak 422.
        $this->deleteJson("/api/users/{$admin->id}")->assertStatus(422);
        // Operator boleh dihapus.
        $this->deleteJson("/api/users/{$other->id}")->assertStatus(200);
    }
}
