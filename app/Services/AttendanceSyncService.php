<?php

namespace App\Services;

use App\Models\AttendanceLog;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Logika kirim absensi ke Mekari Talenta (endpoint Import Fingerprint), dipakai
 * bersama oleh pengiriman MANUAL (AttendanceController) dan AUTO (command terjadwal).
 */
class AttendanceSyncService
{
    public function __construct(
        private MekariTalentaService $talenta,
    ) {}

    /**
     * Kirim semua log 'pending' (dan opsional 'failed') dalam satu batch.
     *
     * @return array{success:bool, message:string, sent:int, failed:int}
     */
    public function sendPending(bool $includeFailed = false): array
    {
        $statuses = $includeFailed ? ['pending', 'failed'] : ['pending'];
        $logs = AttendanceLog::whereIn('status_sync', $statuses)->get();

        if ($logs->isEmpty()) {
            return ['success' => true, 'message' => 'Tidak ada data untuk dikirim', 'sent' => 0, 'failed' => 0];
        }

        $result = $this->sendLogs($logs);

        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'sent' => $result['success'] ? $logs->count() : 0,
            'failed' => $result['success'] ? 0 : $logs->count(),
        ];
    }

    /**
     * Susun log jadi satu CSV badgeno,checktime lalu upload ke Talenta.
     * Status di-update per batch sesuai hasil.
     *
     * @param  Collection<int, AttendanceLog>  $logs
     * @return array{success:bool, message:string}
     */
    public function sendLogs(Collection $logs): array
    {
        $rows = $logs->map(fn (AttendanceLog $log) => [$log->biometric_id_lokal, $log->timestamp]);
        $csv = $this->talenta->buildFingerprintCsv($rows);

        try {
            $response = $this->talenta->importFingerprint($csv);

            if ($this->talenta->wasAccepted($response)) {
                AttendanceLog::whereIn('id', $logs->pluck('id'))
                    ->update(['status_sync' => 'sent', 'error_message' => null]);

                return ['success' => true, 'message' => "Berhasil dikirim ke Talenta ({$logs->count()} data)"];
            }

            $error = "Talenta menolak request (HTTP {$response->status()}): " . $response->body();
            AttendanceLog::whereIn('id', $logs->pluck('id'))
                ->update(['status_sync' => 'failed', 'error_message' => $error]);

            return ['success' => false, 'message' => $error];
        } catch (Throwable $e) {
            AttendanceLog::whereIn('id', $logs->pluck('id'))
                ->update(['status_sync' => 'failed', 'error_message' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}
