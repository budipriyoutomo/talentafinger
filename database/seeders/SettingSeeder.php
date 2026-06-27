<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Katalog pengaturan aplikasi. Idempoten: pakai updateOrCreate by `key`,
 * dan TIDAK menimpa `value` yang sudah diisi admin (hanya isi metadata +
 * value awal saat baris belum ada). Key memakai notasi dot agar selaras
 * dengan config()/.env (lihat Setting::value()).
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $item) {
            $row = Setting::firstOrNew(['key' => $item['key']]);

            // Metadata selalu disinkronkan; value awal hanya saat baris baru.
            $row->fill([
                'group' => $item['group'],
                'type' => $item['type'],
                'label' => $item['label'],
                'description' => $item['description'] ?? null,
            ]);

            if (! $row->exists) {
                $row->value = $item['default'] ?? null;
            }

            $row->save();
        }
    }

    private function catalog(): array
    {
        return [
            // ===== Integrasi Talenta (Mekari) =====
            [
                'key' => 'mekari.base_url',
                'group' => 'talenta',
                'type' => 'text',
                'label' => 'Base URL Talenta API',
                'description' => 'Prod: https://api.mekari.com/v2/talenta/v2 — Sandbox: https://sandbox-api.mekari.com/v2/talenta/v2',
                'default' => config('mekari.base_url'),
            ],
            [
                'key' => 'mekari.client_id',
                'group' => 'talenta',
                'type' => 'text',
                'label' => 'Client ID (HMAC)',
                'description' => 'Dari Mekari Developer Center (developers.mekari.com).',
                'default' => config('mekari.client_id'),
            ],
            [
                'key' => 'mekari.client_secret',
                'group' => 'talenta',
                'type' => 'password',
                'label' => 'Client Secret (HMAC)',
                'description' => 'Rahasia HMAC. Kosongkan untuk tetap memakai nilai .env.',
                'default' => null,
            ],
            [
                'key' => 'mekari.fingerprint_token',
                'group' => 'talenta',
                'type' => 'password',
                'label' => 'Import Fingerprint Token',
                'description' => 'Token endpoint Import Fingerprint (diminta via email ke Mekari).',
                'default' => null,
            ],
            [
                'key' => 'mekari.fingerprint_user_id',
                'group' => 'talenta',
                'type' => 'text',
                'label' => 'Talenta User ID',
                'description' => 'User ID Talenta untuk Import Fingerprint.',
                'default' => config('mekari.fingerprint_user_id'),
            ],
            [
                'key' => 'mekari.rate_limit',
                'group' => 'talenta',
                'type' => 'number',
                'label' => 'Rate Limit (req/menit)',
                'description' => 'Batas request per menit ke API Mekari agar tidak kena HTTP 429.',
                'default' => (string) config('mekari.rate_limit', 60),
            ],

            // ===== Perangkat / ADMS =====
            [
                'key' => 'adms.device_timezone',
                'group' => 'adms',
                'type' => 'text',
                'label' => 'Timezone Perangkat',
                'description' => 'Dipakai saat sinkron jam mesin (mis. Asia/Jakarta).',
                'default' => config('adms.device_timezone', 'Asia/Jakarta'),
            ],
            [
                'key' => 'adms.python_bin',
                'group' => 'adms',
                'type' => 'text',
                'label' => 'Python Binary',
                'description' => 'Untuk skrip pyzk (sync sidik jari TCP 4370). Windows: python, Linux: python3.',
                'default' => config('adms.python_bin', 'python'),
            ],
            [
                'key' => 'adms.sdk_port',
                'group' => 'adms',
                'type' => 'number',
                'label' => 'Port SDK Default',
                'description' => 'Port SDK default mesin ZKTeco (biasanya 4370).',
                'default' => (string) config('adms.sdk_port', 4370),
            ],

            // ===== Umum =====
            [
                'key' => 'app.name',
                'group' => 'general',
                'type' => 'text',
                'label' => 'Nama Aplikasi',
                'description' => 'Nama yang ditampilkan di aplikasi.',
                'default' => config('app.name', 'ADMS'),
            ],

            // ===== Pengiriman absensi ke Talenta (AUTO) =====
            // Pengiriman MANUAL selalu tersedia lewat tombol di halaman Logs.
            [
                'key' => 'attendance.auto_send',
                'group' => 'attendance',
                'type' => 'boolean',
                'label' => 'Aktifkan Auto-kirim',
                'description' => 'Bila aktif, absensi pending dikirim otomatis ke Talenta sesuai jadwal di bawah. '
                    . 'Tetap bisa kirim manual kapan saja dari halaman Logs.',
                'default' => '0',
            ],
            [
                'key' => 'attendance.auto_send_interval',
                'group' => 'attendance',
                'type' => 'number',
                'label' => 'Interval Kirim (menit)',
                'description' => 'Jeda antar pengiriman otomatis. Mis. 15 = kirim batch tiap 15 menit.',
                'default' => '15',
            ],
            [
                'key' => 'attendance.auto_send_window_start',
                'group' => 'attendance',
                'type' => 'time',
                'label' => 'Jam Mulai',
                'description' => 'Auto-kirim hanya berjalan mulai jam ini (format 24 jam, mis. 06:00). Kosongkan = 24 jam.',
                'default' => null,
            ],
            [
                'key' => 'attendance.auto_send_window_end',
                'group' => 'attendance',
                'type' => 'time',
                'label' => 'Jam Selesai',
                'description' => 'Auto-kirim berhenti setelah jam ini (mis. 23:00). Kosongkan = 24 jam.',
                'default' => null,
            ],
            [
                'key' => 'attendance.auto_send_include_failed',
                'group' => 'attendance',
                'type' => 'boolean',
                'label' => 'Ikut Kirim Ulang yang Gagal',
                'description' => 'Bila aktif, log berstatus "failed" ikut dicoba kirim ulang tiap siklus auto-kirim.',
                'default' => '0',
            ],

            // ===== Sinkronisasi jam mesin (AUTO) =====
            // Sinkron MANUAL selalu tersedia lewat tombol "Sync Time" di halaman Machines.
            [
                'key' => 'machine.auto_sync_time',
                'group' => 'machine',
                'type' => 'boolean',
                'label' => 'Aktifkan Auto Sync Time',
                'description' => 'Bila aktif, jam mesin aktif & online disinkronkan otomatis sesuai interval di bawah. '
                    . 'Tetap bisa sinkron manual kapan saja dari halaman Machines.',
                'default' => '0',
            ],
            [
                'key' => 'machine.auto_sync_time_interval',
                'group' => 'machine',
                'type' => 'number',
                'label' => 'Interval Sync (menit)',
                'description' => 'Jeda antar siklus sinkronisasi jam. Mis. 1440 = sekali sehari. Jam mesin jarang melenceng, '
                    . 'jadi interval besar biasanya cukup.',
                'default' => '1440',
            ],
        ];
    }
}
