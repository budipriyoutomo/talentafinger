<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Tombol "Tes Koneksi" Object Storage foto absen di menu Pengaturan. */
class StorageConnectionTestTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role . '-' . uniqid() . '@adms.local',
            'password' => Hash::make('rahasia123'),
            'role' => $role,
        ]);
    }

    public function test_hanya_pengelola_setting_yang_boleh_tes_koneksi(): void
    {
        $this->actingAs($this->user('viewer'))
            ->postJson('/api/settings/storage-test', [])
            ->assertForbidden();
    }

    public function test_kredensial_belum_lengkap_ditolak_dengan_pesan_jelas(): void
    {
        config(['filesystems.disks.idcloudhost.bucket' => null]);

        $this->actingAs($this->user('admin'))
            ->postJson('/api/settings/storage-test', ['key' => 'AK', 'secret' => 'SK'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Koneksi gagal: Nama Bucket belum diisi.');
    }

    public function test_tes_memakai_isian_form_dan_secret_tersimpan_lalu_bersih(): void
    {
        Setting::create([
            'key' => 'filesystems.disks.idcloudhost.secret', 'value' => 'SK-TERSIMPAN',
            'group' => 'storage', 'type' => 'password', 'label' => 'Secret',
        ]);

        $fake = Storage::fake('s3-test');
        Storage::shouldReceive('build')->once()
            ->withArgs(fn ($config) => $config['bucket'] === 'bucket-form'
                && $config['key'] === 'AK-FORM'
                && $config['secret'] === 'SK-TERSIMPAN'
                && $config['use_path_style_endpoint'] === false)
            ->andReturn($fake);

        $this->actingAs($this->user('admin'))
            ->postJson('/api/settings/storage-test', [
                'bucket' => 'bucket-form',
                'key' => 'AK-FORM',
                'use_path_style_endpoint' => '0',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        // File tes dihapus lagi setelah dibaca ulang.
        $this->assertEmpty($fake->allFiles());
    }
}
