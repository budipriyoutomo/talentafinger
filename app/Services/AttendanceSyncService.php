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
     * Banyak log per satu upload CSV ke Talenta.
     *
     * Dihitung dari batas ukuran file Talenta (5MB) dan worst-case panjang baris
     * "badgeno;date;checktime\n": badgeno (talenta_employee_id) maksimal 100
     * karakter (kolom `employees.talenta_employee_id`, lihat migrasi
     * 2026_06_18_034238_create_employees_table), di-utf8mb4-kan jadi maks 400 byte;
     * date (10) + checktime (8) + 2 delimiter + newline = 21 byte. Worst-case
     * ~421 byte/baris, dibulatkan ke 450 byte untuk margin. Target dipakai HANYA
     * ~50% dari 5MB (bukan mepet ke batas) karena Talenta belum resmi mengonfirmasi
     * apakah ada batas JUMLAH baris terpisah dari batas ukuran file — lihat
     * TODO-CSV-Chunk-Size.md. 5.000 baris * 450 byte (worst-case) ~= 2,25MB, ~43%
     * dari 5MB; dalam praktik (badgeno pendek, mis. "1", "TAL-99") jauh lebih kecil
     * lagi. Satu CSV raksasa berisi puluhan ribu baris tetap berisiko kena batas
     * di sisi Talenta dan bikin satu kegagalan menjatuhkan seluruh batch — dipecah
     * segini, satu chunk yang gagal hanya menjatuhkan chunk itu.
     */
    public const CHUNK_SIZE = 5000;

    public function __construct(
        private MekariTalentaService $talenta,
    ) {}

    /**
     * Kirim ulang log yang cocok filter, DIPECAH per CHUNK_SIZE.
     *
     * $status menentukan kumpulan yang dikirim ulang: 'failed' (percobaan
     * sebelumnya gagal) atau 'sent' (sudah pernah terkirim, dikirim lagi atas
     * permintaan user — lihat AttendanceLog::RESENDABLE_STATUSES). Status tujuan
     * sekaligus dipakai sebagai filter tabel, jadi yang diproses persis sama
     * dengan yang ditampilkan tab-nya.
     *
     * Daftar id di-snapshot lebih dulu (pluck) alih-alih meng-iterasi query
     * hidup, karena pengiriman MENGUBAH status_sync — kolom yang sama yang
     * dipakai memfilter. Meng-chunk query semacam itu akan melewati baris
     * diam-diam saat halaman bergeser. Snapshot id membuat yang diproses persis
     * sama dengan yang dihitung di awal.
     *
     * $onProgress dipanggil tiap chunk selesai supaya pemanggil (job background)
     * bisa menulis progres yang dipolling UI.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $ids  Kosong = semua yang cocok filter.
     * @param  callable(int, int):void|null  $onProgress  (selesai, total)
     * @return array{success:bool, message:string, sent:int, failed:int, total:int}
     */
    public function resendChunked(
        array $filters = [],
        array $ids = [],
        ?User $user = null,
        ?callable $onProgress = null,
        string $status = 'failed',
    ): array {
        // Nilai asing (mis. dari baris job lama) jatuh ke 'failed' supaya tak ada
        // jalur yang diam-diam mengirim ulang status yang tak boleh dikirim ulang.
        $status = in_array($status, AttendanceLog::RESENDABLE_STATUSES, true) ? $status : 'failed';

        $allIds = AttendanceLog::visibleTo($user)
            ->applyFilters($filters + ['status' => $status])
            ->where('status_sync', $status)
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('created_at')
            ->pluck('id')
            ->all();

        if (empty($allIds)) {
            return ['success' => true, 'message' => 'Tidak ada data untuk dikirim ulang',
                'sent' => 0, 'failed' => 0, 'total' => 0];
        }

        return $this->sendIdsChunked($allIds, 'Kirim ulang', $onProgress);
    }

    /**
     * Ambil ulang log by id per CHUNK_SIZE lalu kirim, chunk demi chunk. Dipakai
     * bersama oleh resendChunked() DAN sendPending(), supaya SEMUA jalur
     * pengiriman batch (termasuk auto-send terjadwal) melewati batas ukuran yang
     * sama — backlog yang menumpuk tak lagi bisa jadi satu CSV raksasa dalam
     * satu request.
     *
     * @param  array<int, string>  $ids
     * @return array{success:bool, message:string, sent:int, failed:int, total:int}
     */
    private function sendIdsChunked(array $ids, string $label, ?callable $onProgress = null): array
    {
        $total = count($ids);
        $sent = 0;
        $failed = 0;
        $done = 0;
        $errors = [];

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunkIds) {
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

        $message = "{$label} selesai: {$sent} terkirim, {$failed} gagal (dari {$total} log).";
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
     * Kirim semua log 'pending' (dan opsional 'failed'), DIPECAH per CHUNK_SIZE.
     *
     * Dipakai baik oleh tombol "kirim semua" (manual) MAUPUN command terjadwal
     * AutoSendAttendance. Tanpa chunking, backlog yang menumpuk (mis. setelah
     * mesin/queue mati semalaman) akan jadi satu CSV raksasa dalam satu request —
     * lihat sendIdsChunked().
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
        $ids = AttendanceLog::visibleTo($user)->whereIn('status_sync', $statuses)->pluck('id')->all();

        if (empty($ids)) {
            return ['success' => true, 'message' => 'Tidak ada data untuk dikirim', 'sent' => 0, 'failed' => 0];
        }

        return $this->sendIdsChunked($ids, 'Kirim');
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
        // Jaring pengaman: absen manual yang belum disetujui / ditolak supervisor
        // TIDAK boleh sampai ke Talenta lewat jalur apa pun (kirim satuan, batch,
        // atau kirim ulang). Dilewati diam-diam, status tak diubah.
        $logs = $logs->reject(
            fn (AttendanceLog $log) => in_array($log->status_sync, AttendanceLog::UNSENDABLE_STATUSES, true)
        )->values();

        if ($logs->isEmpty()) {
            return [
                'success' => false,
                'message' => 'Tidak ada log yang boleh dikirim (absen foto belum disetujui / ditolak).',
                'sent' => 0,
                'failed' => 0,
            ];
        }

        $badgeOf = $this->resolveBadges($logs);
        $badges = $logs->mapWithKeys(fn (AttendanceLog $log) => [$log->id => $badgeOf($log)]);

        // Pisahkan log yang punya badgeno valid vs yang tidak.
        [$mapped, $unmapped] = $logs->partition(
            fn (AttendanceLog $log) => filled($badges[$log->id])
        );

        // Log tanpa karyawan/talenta_employee_id: tandai gagal, jangan kirim PIN mentah.
        // Pesannya beda per sumber: log manual tak memakai PIN sama sekali.
        [$unmappedManual, $unmappedMachine] = $unmapped->partition(fn (AttendanceLog $log) => $log->employee_id !== null);

        if ($unmappedMachine->isNotEmpty()) {
            AttendanceLog::whereIn('id', $unmappedMachine->pluck('id'))->update([
                'status_sync' => 'failed',
                'error_message' => 'PIN tak terhubung ke karyawan Talenta (Biometric ID/talenta_employee_id belum di-set).',
            ]);
        }

        if ($unmappedManual->isNotEmpty()) {
            AttendanceLog::whereIn('id', $unmappedManual->pluck('id'))->update([
                'status_sync' => 'failed',
                'error_message' => 'Karyawan belum punya Talenta Employee ID (talenta_employee_id belum di-set).',
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

        $rows = $mapped->map(fn (AttendanceLog $log) => [$badges[$log->id], $log->timestamp]);
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
     * Resolusi badgeno (talenta_employee_id) untuk semua log, maksimal dua query
     * (hindari N+1). Mengembalikan fungsi log -> badgeno|null.
     *
     *   - Log MESIN  : PIN global, employees.biometric_id == log.biometric_id_lokal.
     *   - Log MANUAL : langsung dari log.employee_id (karyawan yang login) — tak
     *                  bergantung pada PIN, yang bisa saja belum di-set.
     *
     * @param  Collection<int, AttendanceLog>  $logs
     * @return callable(AttendanceLog): ?string
     */
    private function resolveBadges(Collection $logs): callable
    {
        [$manual, $machine] = $logs->partition(fn (AttendanceLog $log) => $log->employee_id !== null);

        $pins = $machine->pluck('biometric_id_lokal')->filter()->unique()->all();
        $byPin = $pins
            ? Employee::whereIn('biometric_id', $pins)->pluck('talenta_employee_id', 'biometric_id')->all()
            : [];

        $employeeIds = $manual->pluck('employee_id')->unique()->all();
        $byEmployee = $employeeIds
            ? Employee::whereIn('id', $employeeIds)->pluck('talenta_employee_id', 'id')->all()
            : [];

        return fn (AttendanceLog $log) => $log->employee_id !== null
            ? ($byEmployee[$log->employee_id] ?? null)
            : ($byPin[$log->biometric_id_lokal] ?? null);
    }
}
