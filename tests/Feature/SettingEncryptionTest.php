<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** B3: setting bertipe 'password' tersimpan terenkripsi (APP_KEY). */
class SettingEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'mekari.client_secret';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();
    }

    private function secretRow(?string $raw = null): Setting
    {
        return Setting::create([
            'key' => self::KEY, 'value' => $raw, 'group' => 'talenta', 'type' => 'password', 'label' => 'Secret',
        ]);
    }

    private function rawValue(string $key = self::KEY): ?string
    {
        return DB::table('settings')->where('key', $key)->value('value');
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@adms.local',
            'password' => Hash::make('rahasia123'), 'role' => 'admin',
        ]);
    }

    private function runMigration(string $direction = 'up'): void
    {
        $migration = require database_path('migrations/2026_11_05_000001_encrypt_password_settings.php');
        $migration->{$direction}();
    }

    public function test_put_stores_ciphertext_and_value_returns_plain(): void
    {
        $this->secretRow();

        Setting::put(self::KEY, 'super-rahasia');

        $raw = $this->rawValue();
        $this->assertNotSame('super-rahasia', $raw);
        $this->assertStringNotContainsString('super-rahasia', $raw);
        $this->assertSame('super-rahasia', Crypt::decryptString($raw));
        $this->assertSame('super-rahasia', Setting::value(self::KEY));
    }

    public function test_non_password_settings_stay_plain(): void
    {
        Setting::create(['key' => 'mekari.client_id', 'value' => null, 'group' => 'talenta', 'type' => 'string', 'label' => 'ID']);

        Setting::put('mekari.client_id', 'abc');

        $this->assertSame('abc', $this->rawValue('mekari.client_id'));
    }

    public function test_legacy_plaintext_is_still_readable(): void
    {
        $this->secretRow('plain-lama');

        $this->assertSame('plain-lama', Setting::value(self::KEY));
    }

    public function test_empty_secret_is_not_encrypted_and_falls_back_to_config(): void
    {
        config([self::KEY => 'dari-env']);
        $this->secretRow('x');

        Setting::put(self::KEY, '');

        $this->assertSame('', $this->rawValue());
        $this->assertSame('dari-env', Setting::value(self::KEY));
    }

    public function test_migration_encrypts_plaintext_and_is_idempotent(): void
    {
        $this->secretRow('plain-lama');
        Setting::create(['key' => 'mekari.fingerprint_token', 'value' => null, 'group' => 'talenta', 'type' => 'password', 'label' => 'Token']);
        Setting::create(['key' => 'mekari.client_id', 'value' => 'id-biasa', 'group' => 'talenta', 'type' => 'string', 'label' => 'ID']);

        $this->runMigration();
        $once = $this->rawValue();
        $this->assertSame('plain-lama', Crypt::decryptString($once));

        // Diulang: tidak dienkripsi dua kali.
        $this->runMigration();
        $this->assertSame($once, $this->rawValue());

        $this->assertNull($this->rawValue('mekari.fingerprint_token'));
        $this->assertSame('id-biasa', $this->rawValue('mekari.client_id'));
        $this->assertSame('plain-lama', Setting::value(self::KEY));
    }

    public function test_migration_down_restores_plaintext(): void
    {
        $this->secretRow('plain-lama');

        $this->runMigration();
        $this->runMigration('down');

        $this->assertSame('plain-lama', $this->rawValue());
    }

    public function test_migration_flushes_settings_cache(): void
    {
        $this->secretRow('plain-lama');
        Setting::cached(); // isi cache dengan plaintext

        $this->runMigration();

        $this->assertSame($this->rawValue(), Setting::cached()[self::KEY]['value']);
    }

    public function test_settings_page_preview_masks_the_real_value(): void
    {
        $this->secretRow();
        Setting::put(self::KEY, 'abcdefghijklmnop');
        $this->actingAs($this->admin());

        $props = $this->get('/settings')->assertOk()->viewData('page')['props'];
        $item = collect($props['groups']['talenta'])->firstWhere('key', self::KEY);

        $this->assertSame('abc••••mnop', $item['preview']);
        $this->assertSame('', $item['value']);
        $this->assertTrue($item['is_set']);
    }

    public function test_empty_input_does_not_overwrite_secret(): void
    {
        $this->secretRow();
        Setting::put(self::KEY, 'tetap');
        $this->actingAs($this->admin());

        $this->putJson('/api/settings', ['settings' => [self::KEY => '']])->assertOk();

        $this->assertSame('tetap', Setting::value(self::KEY));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'setting.updated']);
    }

    public function test_resubmitting_same_secret_is_not_audited_as_changed(): void
    {
        $this->secretRow();
        Setting::put(self::KEY, 'sama');
        $this->actingAs($this->admin());

        $this->putJson('/api/settings', ['settings' => [self::KEY => 'sama']])->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'setting.updated']);
    }

    public function test_changed_secret_is_audited_without_its_value(): void
    {
        $this->secretRow();
        Setting::put(self::KEY, 'lama-sekali');
        $this->actingAs($this->admin());

        $this->putJson('/api/settings', ['settings' => [self::KEY => 'baru-sekali']])->assertOk();

        $log = AuditLog::where('action', 'setting.updated')->firstOrFail();
        $this->assertSame(['from' => null, 'to' => 'diganti'], $log->changes[self::KEY]);
        $json = json_encode($log->toArray());
        $this->assertStringNotContainsString('lama-sekali', $json);
        $this->assertStringNotContainsString('baru-sekali', $json);
        $this->assertSame('baru-sekali', Setting::value(self::KEY));
    }

    public function test_seeder_encrypts_password_defaults(): void
    {
        config([self::KEY => 'dari-env']);

        $this->seed(\Database\Seeders\SettingSeeder::class);

        $raw = $this->rawValue();
        $this->assertNotSame('dari-env', $raw);
        $this->assertSame('dari-env', Setting::value(self::KEY));
    }
}
