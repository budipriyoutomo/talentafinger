<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
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
