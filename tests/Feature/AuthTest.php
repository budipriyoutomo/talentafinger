<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@adms.local',
            'password' => Hash::make('password'),
        ]);
    }

    public function test_guest_is_redirected_to_login_from_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_guest_cannot_access_api(): void
    {
        // API kini di bawah auth; tamu mendapat 401/redirect, bukan data.
        $this->getJson('/api/machines')->assertStatus(401);
    }

    public function test_user_can_login_and_reach_dashboard(): void
    {
        $this->user();

        $this->withSession(['_token' => 'tok'])->post('/login', [
            '_token' => 'tok',
            'email' => 'admin@adms.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticated();
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->user();

        $this->from('/login')->withSession(['_token' => 'tok'])->post('/login', [
            '_token' => 'tok',
            'email' => 'admin@adms.local',
            'password' => 'salah',
        ])->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_authenticated_user_can_access_api(): void
    {
        $this->actingAs($this->user());

        $this->getJson('/api/machines')->assertStatus(200);
    }
}
