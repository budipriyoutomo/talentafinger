<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Audit log, nonaktifkan akun, dan jejak login terakhir. */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'viewer', array $attrs = []): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@adms.local',
            'password' => Hash::make('rahasia123'),
            'role' => $role,
            ...$attrs,
        ]);
    }

    // ------------------------------------------------------------------ login

    public function test_successful_login_records_audit_and_last_login(): void
    {
        $user = $this->user();

        $this->post('/login', ['email' => $user->email, 'password' => 'rahasia123'])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame('127.0.0.1', $user->last_login_ip);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_failed_login_is_recorded_with_attempted_email(): void
    {
        $this->post('/login', ['email' => 'siapa@adms.local', 'password' => 'salah123']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login_failed', 'user_id' => null, 'user_name' => 'siapa@adms.local',
        ]);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->user('viewer', ['is_active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'rahasia123'])
            ->assertSessionHasErrors(['email' => 'Akun Anda dinonaktifkan. Hubungi admin.']);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_blocked', 'user_id' => $user->id]);
    }

    public function test_inactive_account_with_wrong_password_gets_generic_error(): void
    {
        $user = $this->user('viewer', ['is_active' => false]);

        // Status akun tak boleh bocor ke orang yang tak tahu password-nya.
        $this->post('/login', ['email' => $user->email, 'password' => 'salah123'])
            ->assertSessionHasErrors(['email' => 'Email atau password salah.']);
    }

    public function test_deactivating_user_terminates_existing_session(): void
    {
        $user = $this->user('admin');
        $this->actingAs($user);
        $this->get('/dashboard')->assertOk();

        $user->update(['is_active' => false]);

        $this->getJson('/api/machines')->assertStatus(401);
        $this->assertGuest();
    }

    // ------------------------------------------------------- user management

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin);

        $this->putJson("/api/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'admin', 'is_active' => false,
        ])->assertStatus(422);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_user_update_is_audited_without_password_value(): void
    {
        $this->actingAs($this->user('admin'));
        $target = $this->user('viewer');

        $this->putJson("/api/users/{$target->id}", [
            'name' => 'Viewer', 'email' => $target->email, 'role' => 'operator',
            'password' => 'RahasiaBaru99', 'is_active' => false,
        ])->assertOk()->assertJsonPath('is_active', false);

        $log = AuditLog::where('action', 'user.updated')->firstOrFail();
        $this->assertSame((string) $target->id, $log->target_id);
        $this->assertSame(['from' => 'viewer', 'to' => 'operator'], $log->changes['role']);
        $this->assertSame(['from' => true, 'to' => false], $log->changes['is_active']);
        $this->assertSame('direset', $log->changes['password']['to']);
        $this->assertStringNotContainsString('RahasiaBaru99', json_encode($log->changes));
    }

    public function test_secret_settings_are_masked_in_audit(): void
    {
        Setting::create(['key' => 'mekari.client_secret', 'value' => 'lama', 'group' => 'talenta', 'type' => 'password', 'label' => 'Secret']);
        Setting::create(['key' => 'attendance.auto_send', 'value' => '0', 'group' => 'attendance', 'type' => 'boolean', 'label' => 'Auto']);
        $this->actingAs($this->user('admin'));

        $this->putJson('/api/settings', ['settings' => [
            'mekari.client_secret' => 'super-rahasia-baru',
            'attendance.auto_send' => true,
        ]])->assertOk();

        $log = AuditLog::where('action', 'setting.updated')->firstOrFail();
        $this->assertSame(['from' => null, 'to' => 'diganti'], $log->changes['mekari.client_secret']);
        $this->assertSame(['from' => '0', 'to' => true], $log->changes['attendance.auto_send']);
        $this->assertStringNotContainsString('super-rahasia-baru', json_encode($log->changes));
    }

    // ----------------------------------------------------------- audit page

    public function test_audit_page_requires_permission_and_filters_by_group(): void
    {
        $admin = $this->user('admin');
        AuditLog::create(['action' => 'auth.login', 'user_name' => 'A']);
        AuditLog::create(['action' => 'user.deleted', 'user_name' => 'B']);

        $this->actingAs($this->user('manager'));
        $this->get('/audit-logs')->assertStatus(403);

        $this->actingAs($admin);
        $this->get('/audit-logs?action=user')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('AuditLogs')
                ->has('logs', 1)
                ->where('logs.0.action', 'user.deleted'));
    }

    public function test_old_audit_logs_are_pruned(): void
    {
        $old = AuditLog::create(['action' => 'auth.login']);
        $old->forceFill(['created_at' => now()->subDays(AuditLog::RETENTION_DAYS + 1)])->save();
        $fresh = AuditLog::create(['action' => 'auth.logout']);

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

        $this->assertNull(AuditLog::find($old->id));
        $this->assertNotNull(AuditLog::find($fresh->id));
    }
}
