<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\AttendancePhoto;
use Aws\Exception\AwsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SettingController extends Controller
{
    /**
     * Simpan banyak setting sekaligus. Hanya key yang dikenal yang diproses.
     * Field password yang dikirim kosong DIABAIKAN (tidak menimpa nilai lama),
     * supaya rahasia yang tak ditampilkan ke browser tidak terhapus tak sengaja.
     *
     * Admin-only: setting memuat kredensial Talenta dan sakelar otomatisasi.
     */
    public function update(Request $request)
    {
        $this->authorizePermission($request, 'setting.manage');

        $data = $request->validate([
            'settings' => 'required|array|min:1',
            'settings.*' => 'nullable',
        ]);

        $known = Setting::pluck('type', 'key'); // key => type
        $current = Setting::pluck('value', 'key');
        $updated = 0;
        $changes = [];

        foreach ($data['settings'] as $key => $value) {
            if (! $known->has($key)) {
                continue;
            }

            // Jangan timpa password dengan input kosong.
            if ($known[$key] === 'password' && ($value === null || $value === '')) {
                continue;
            }

            // Samakan dengan cara Setting::put menyimpan (bool -> '1'/'0').
            $stored = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            if ((string) $current->get($key) !== $stored) {
                // Rahasia (kredensial Talenta) tak pernah masuk audit log.
                $changes[$key] = $known[$key] === 'password'
                    ? ['from' => null, 'to' => 'diganti']
                    : ['from' => $current->get($key), 'to' => $value];
            }

            Setting::put($key, $value);
            $updated++;
        }

        if ($changes) {
            AuditLog::record('setting.updated', changes: $changes);
        }

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'message' => "{$updated} pengaturan disimpan.",
        ]);
    }

    /**
     * POST /api/settings/storage-test — Tes Koneksi Object Storage foto absen.
     * Memakai nilai form yang sedang diisi (boleh belum disimpan); field kosong,
     * termasuk Secret Key, jatuh ke nilai tersimpan / .env.
     */
    public function testStorage(Request $request)
    {
        $this->authorizePermission($request, 'setting.manage');

        $data = $request->validate([
            'endpoint' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:64',
            'bucket' => 'nullable|string|max:255',
            'key' => 'nullable|string|max:255',
            'secret' => 'nullable|string|max:255',
            'use_path_style_endpoint' => 'nullable|boolean',
        ]);

        if (isset($data['use_path_style_endpoint'])) {
            $data['use_path_style_endpoint'] = (bool) $data['use_path_style_endpoint'];
        }

        $started = microtime(true);

        try {
            AttendancePhoto::testS3($data);
        } catch (\Throwable $e) {
            $message = 'Koneksi gagal: ' . $this->storageError($e);

            // Key valid tapi bucket tak ada: tunjukkan bucket yang benar-benar dimiliki key ini.
            if (str_contains($message, 'NoSuchBucket')) {
                try {
                    $buckets = AttendancePhoto::visibleS3Buckets($data);
                    $message .= "\nBucket yang terlihat oleh Access Key ini: "
                        . ($buckets ? implode(', ', $buckets) : '(tidak ada; buat bucket dulu di panel, atau key ini milik user lain)');
                } catch (\Throwable) {
                    // Daftar bucket hanya pelengkap; pesan utama tetap dikirim.
                }
            }

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 422);
        }

        $ms = (int) round((microtime(true) - $started) * 1000);

        return response()->json([
            'success' => true,
            'message' => "Koneksi berhasil: tulis, baca & hapus file tes OK ({$ms} ms).",
        ]);
    }

    /** Pesan error S3 yang bisa dibaca admin (kode AWS lebih jelas dari stack Flysystem). */
    private function storageError(\Throwable $e): string
    {
        $hints = [
            'InvalidAccessKeyId' => 'Access Key tidak dikenal.',
            'SignatureDoesNotMatch' => 'Secret Key salah.',
            'NoSuchBucket' => 'Bucket tidak ditemukan, cek nama bucket.',
            'AccessDenied' => 'Akses ditolak, key tidak punya izin ke bucket ini.',
        ];

        for ($cur = $e; $cur; $cur = $cur->getPrevious()) {
            if ($cur instanceof AwsException) {
                $code = $cur->getAwsErrorCode();

                if ($code === null) {
                    // Tanpa kode AWS = gagal di jaringan (DNS, timeout, SSL). Detail ke log.
                    Log::warning('Tes koneksi Object Storage gagal', ['error' => $cur->getMessage()]);

                    return 'Endpoint tidak bisa dihubungi (DNS / jaringan / SSL / timeout). Cek alamat Endpoint S3.';
                }

                return $code . ' — ' . ($hints[$code] ?? ($cur->getAwsErrorMessage() ?: 'lihat log server.'));
            }
        }

        return $e->getMessage();
    }
}
