<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Logika kirim absensi ke Mekari Talenta (endpoint Import Fingerprint), dipakai
 * bersama oleh pengiriman MANUAL (AttendanceController) dan AUTO (command terjadwal).
 */
class AttendanceSyncService
{
    /**
     * Banyak log per satu upload CSV ke Talenta. Bukan angka ajaib: satu CSV
     * raksasa berisi puluhan ribu baris berisiko kena batas ukuran request di
     * sisi Talenta dan bikin satu kegagalan menjatuhkan seluruh batch. Dipecah
     * segini, satu chunk yang gagal hanya menjatuhkan chunk itu.
     */
    public const CHUNK_SIZE = 500;

    public function __construct(
        private MekariTalentaService $talenta,
    ) {}

    /**
     * Kirim ulang log gagal yang cocok filter, DIPECAH per CHUNK_SIZE.
     *
     * Daftar id di-snapshot lebih dulu (pluck) alih-alih meng-iterasi query
     * hidup, karena pengiriman MENGUBAH status_sync dari 'failed' jadi 'sent' —
     * kolom yang sama yang dipakai memfilter. Meng-chunk query semacam itu akan
     * melewati baris diam-diam saat halaman bergeser. Snapshot id membuat yang
     * diproses persis sama dengan yang dihitung di awal.
     *
     * $onProgress dipanggil tiap chunk selesai supaya pemanggil (job background)
     * bisa menulis progres yang dipolling UI.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $ids  Kosong = semua yang cocok filter.
     * @param  callable(int, int):void|null  $onProgress  (selesai, total)
     * @return array{success:bool, message:string, sent:int, failed:int, total:int}
     */
    public function resendFailedChunked(
        array $filters = [],
        array $ids = [],
        ?User $user = null,
        ?callable $onProgress = null,
    ): array {
        $allIds = AttendanceLog::visibleTo($user)
            ->applyFilters($filters + ['status' => 'failed'])
            ->where('status_sync', 'failed')
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('created_at')
            ->pluck('id')
            ->all();

        $total = count($allIds);

        if ($total === 0) {
            return ['success' => true, 'message' => 'Tidak ada data gagal untuk dikirim ulang',
                'sent' => 0, 'failed' => 0, 'total' => 0];
        }

        $sent = 0;
        $failed = 0;
        $done = 0;
        $errors = [];

        foreach (array_chunk($allIds, self::CHUNK_SIZE) as $chunkIds) {
            $logs = AttendanceLog::whereIn('id', $chunkIds)->get();

            // Satu chunk yang meledak tak boleh menghentikan chunk berikutnya —
            // kalau tidak, gangguan sesaat di tengah jalan membuang kerja yang
            // sudah berhasil sebelumnya.
            try {
                $result = $this->sendLogs($logs);
                $sent += $result['sent'];
                $failed += $result['failed'];
                if (! $result['success']) {
                    $errors[] = $result['message'];
                }
            } catch (Throwable $e) {
                $failed += $logs->count();
                $errors[] = $e->getMessage();
                AttendanceLog::whereIn('id', $chunkIds)
                    ->where('status_sync', '!=', 'sent')
                    ->update(['status_sync' => 'failed', 'error_message' => $e->getMessage()]);
            }

            $done += count($chunkIds);

            if ($onProgress) {
                $onProgress($done, $total);
            }
        }

        $message = "Kirim ulang selesai: {$sent} terkirim, {$failed} gagal (dari {$total} log).";
        if ($errors) {
            $message .= ' Contoh error: ' . $errors[0];
        }

        return [
            'success' => $failed === 0,
            'message' => $message,
            'sent' => $sent,
            'failed' => $failed,
            'total' => $total,
        ];
    }

    /**
     * Kirim semua log 'pending' (dan opsional 'failed') dalam satu batch.
     *
     * $user membatasi log ke outlet wewenangnya, supaya tombol "kirim semua" milik
     * seorang manajer tidak ikut mengirim log outlet orang lain. null = konteks
     * SISTEM (command terjadwal AutoSendAttendance) yang memang tanpa batas.
     *
     * @return array{success:bool, message:string, sent:int, failed:int}
     */
    public function sendPending(bool $includeFailed = false, ?User $user = null): array
    {
        $statuses = $includeFailed ? ['pending', 'failed'] : ['pending'];
        $logs = AttendanceLog::visibleTo($user)->whereIn('status_sync', $statuses)->get();

        if ($logs->isEmpty()) {
            return ['success' => true, 'message' => 'Tidak ada data untuk dikirim', 'sent' => 0, 'failed' => 0];
        }

        return $this->sendLogs($logs);
    }

    /**
     * Kirim ulang HANYA log yang berstatus 'failed' — MANUAL (tombol di tab Gagal).
     * Bila $ids diisi, hanya log gagal dengan id tersebut yang dikirim ulang
     * (dipakai saat user mencentang baris tertentu); kosong = semua yang gagal.
     *
     * $user membatasi ke outlet wewenangnya; null = konteks sistem (tanpa batas).
     *
     * @param  array<int, string>  $ids
     */
    public function sendFailed(array $ids = [], ?User $user = null): array
    {
        $logs = AttendanceLog::visibleTo($user)
            ->where('status_sync', 'failed')
            ->when($ids, fn ($query) => $query->whereIn('id', $ids))
            ->get();

        if ($logs->isEmpty()) {
            return ['success' => true, 'message' => 'Tidak ada data gagal untuk dikirim ulang', 'sent' => 0, 'failed' => 0];
        }

        return $this->sendLogs($logs);
    }

    /**
     * Susun log jadi satu CSV badgeno;date;checktime lalu upload ke Talenta.
     *
     * badgeno di-RESOLVE dari karyawan: employees.biometric_id (PIN global) ==
     * log.biometric_id_lokal -> employee.talenta_employee_id. Log yang PIN-nya tak
     * cocok ke karyawan mana pun, atau karyawannya belum punya talenta_employee_id,
     * TIDAK dikirim (PIN mentah akan ditolak company Talenta) dan ditandai 'failed'.
     *
     * @param  Collection<int, AttendanceLog>  $logs
     * @return array{success:bool, message:string, sent:int, failed:int}
     */
    public function sendLogs(Collection $logs): array
    {
        $badges = $this->resolveBadges($logs);

        // Pisahkan log yang punya badgeno valid vs yang tidak.
        [$mapped, $unmapped] = $logs->partition(
            fn (AttendanceLog $log) => filled($badges[$log->biometric_id_lokal] ?? null)
        );

        // Log tanpa karyawan/talenta_employee_id: tandai gagal, jangan kirim PIN mentah.
        if ($unmapped->isNotEmpty()) {
            AttendanceLog::whereIn('id', $unmapped->pluck('id'))->update([
                'status_sync' => 'failed',
                'error_message' => 'PIN tak terhubung ke karyawan Talenta (Biometric ID/talenta_employee_id belum di-set).',
            ]);
        }

        if ($mapped->isEmpty()) {
            return [
                'success' => false,
                'message' => "Tidak ada log dengan karyawan Talenta yang valid ({$unmapped->count()} dilewati).",
                'sent' => 0,
                'failed' => $unmapped->count(),
            ];
        }

        $rows = $mapped->map(fn (AttendanceLog $log) => [$badges[$log->biometric_id_lokal], $log->timestamp]);
        $csv = $this->talenta->buildFingerprintCsv($rows);

        try {
            $response = $this->talenta->importFingerprint($csv);

            if ($this->talenta->wasAccepted($response)) {
                AttendanceLog::whereIn('id', $mapped->pluck('id'))
                    ->update(['status_sync' => 'sent', 'error_message' => null]);

                $note = $unmapped->isEmpty() ? '' : " ({$unmapped->count()} dilewati tanpa mapping)";

                return [
                    'success' => true,
                    'message' => "Berhasil dikirim ke Talenta ({$mapped->count()} data){$note}",
                    'sent' => $mapped->count(),
                    'failed' => $unmapped->count(),
                ];
            }

            $error = "Talenta menolak request (HTTP {$response->status()}): " . $response->body();
            AttendanceLog::whereIn('id', $mapped->pluck('id'))
                ->update(['status_sync' => 'failed', 'error_message' => $error]);

            return ['success' => false, 'message' => $error, 'sent' => 0, 'failed' => $logs->count()];
        } catch (Throwable $e) {
            AttendanceLog::whereIn('id', $mapped->pluck('id'))
                ->update(['status_sync' => 'failed', 'error_message' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'sent' => 0, 'failed' => $logs->count()];
        }
    }

    /**
     * Resolusi badgeno (talenta_employee_id) untuk semua log dalam satu query,
     * dihindarkan N+1. PIN global: employees.biometric_id == log.biometric_id_lokal.
     * Key: biometric_id (PIN).
     *
     * @param  Collection<int, AttendanceLog>  $logs
     * @return array<string, ?string>
     */
    private function resolveBadges(Collection $logs): array
    {
        $pins = $logs->pluck('biometric_id_lokal')->unique()->all();

        return Employee::whereIn('biometric_id', $pins)
            ->pluck('talenta_employee_id', 'biometric_id')
            ->all();
    }
}
