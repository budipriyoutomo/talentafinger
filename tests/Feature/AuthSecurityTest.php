<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** P0 keamanan auth: rate limit login, password sementara, sesi lama dicabut. */
class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::create([
            'name' => 'Budi',
            'email' => 'budi@adms.local',
            'password' => Hash::make('rahasia123'),
            'role' => 'viewer',
            ...$attrs,
        ]);
    }

    private function login(string $password = 'rahasia123')
    {
        return $this->post('/login', ['email' => 'budi@adms.local', 'password' => $password]);
    }

    public function test_login_is_locked_after_five_failed_attempts(): void
    {
        $this->user();

        foreach (range(1, 5) as $_) {
            $this->login('salah')->assertSessionHasErrors('email');
        }

        // Password benar pun ditolak selama terkunci.
        $this->login()->assertSessionHasErrors('email');
        $this->assertStringContainsString('Terlalu banyak percobaan login', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_successful_login_resets_failed_attempt_counter(): void
    {
        $this->user();

        foreach (range(1, 4) as $_) {
            $this->login('salah');
        }
        $this->login()->assertRedirect('/dashboard');
        $this->post('/logout');

        // Hitungan sudah bersih: 4 gagal lagi belum mengunci.
        foreach (range(1, 4) as $_) {
            $this->login('salah');
        }
        $this->login()->assertRedirect('/dashboard');
    }

    public function test_user_with_temporary_password_is_confined_to_change_password_page(): void
    {
        $user = $this->user(['must_change_password' => true]);
        $this->actingAs($user);

        $this->get('/dashboard')->assertRedirect('/password');
        $this->getJson('/api/machines')->assertStatus(403);
        $this->get('/password')->assertOk();
    }

    public function test_change_password_validates_current_and_strength(): void
    {
        $this->actingAs($this->user());

        $this->put('/password', [
            'current_password' => 'keliru123',
            'password' => 'baruSekali9',
            'password_confirmation' => 'baruSekali9',
        ])->assertSessionHasErrors('current_password');

        $this->put('/password', [
            'current_password' => 'rahasia123',
            'password' => 'hanyahuruf',
            'password_confirmation' => 'hanyahuruf',
        ])->assertSessionHasErrors('password');
    }

    public function test_change_password_clears_temporary_flag(): void
    {
        $user = $this->user(['must_change_password' => true]);
        $this->actingAs($user);

        $this->put('/password', [
            'current_password' => 'rahasia123',
            'password' => 'baruSekali9',
            'password_confirmation' => 'baruSekali9',
        ])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('baruSekali9', $user->password));
        $this->get('/dashboard')->assertOk();
    }

    public function test_changing_password_elsewhere_logs_out_existing_session(): void
    {
        $user = $this->user();
        $this->login()->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();

        // Mis. admin me-reset password user ini dari perangkat lain.
        $user->update(['password' => Hash::make('direset99')]);
        // Request nyata selalu memuat ulang user dari DB; di test guard
        // menyimpan objek lama, jadi lupakan dulu.
        $this->app['auth']->forgetGuards();

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_admin_created_and_reset_passwords_are_temporary(): void
    {
        $admin = $this->user(['email' => 'admin@adms.local', 'role' => 'admin']);
        $this->actingAs($admin);

        $this->postJson('/api/users', [
            'name' => 'Lemah', 'email' => 'lemah@adms.local', 'password' => 'hanyahuruf', 'role' => 'viewer',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $id = $this->postJson('/api/users', [
            'name' => 'Sari', 'email' => 'sari@adms.local', 'password' => 'sementara1', 'role' => 'viewer',
        ])->assertStatus(201)->assertJsonPath('must_change_password', true)->json('id');

        User::whereKey($id)->update(['must_change_password' => false]);

        $this->putJson("/api/users/{$id}", [
            'name' => 'Sari', 'email' => 'sari@adms.local', 'password' => 'direset99', 'role' => 'viewer',
        ])->assertOk()->assertJsonPath('must_change_password', true);

        // Admin mengganti password-nya SENDIRI lewat form user: tidak dipaksa.
        $this->putJson("/api/users/{$admin->id}", [
            'name' => 'Budi', 'email' => 'admin@adms.local', 'password' => 'adminBaru1', 'role' => 'admin',
        ])->assertOk()->assertJsonPath('must_change_password', false);
    }

    public function test_seeder_does_not_overwrite_existing_admin_password(): void
    {
        $admin = $this->user(['email' => 'admin@adms.local', 'role' => 'operator', 'password' => Hash::make('milikAdmin1')]);

        $this->seed(AdminUserSeeder::class);

        $admin->refresh();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('milikAdmin1', $admin->password));
    }

    public function test_seeder_creates_admin_with_temporary_password(): void
    {
        $this->seed(AdminUserSeeder::class);

        $this->assertTrue(User::where('email', 'admin@adms.local')->value('must_change_password'));
    }

    public function test_seeder_refuses_default_password_in_production(): void
    {
        $this->app['env'] = 'production';

        // Langsung run(): perintah db:seed di production meminta konfirmasi.
        $this->expectException(\RuntimeException::class);
        (new AdminUserSeeder)->run();
    }
}
