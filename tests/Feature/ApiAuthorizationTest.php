<?php

namespace Tests\Feature;

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
