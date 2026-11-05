<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\AttendancePhoto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hapus FILE foto absen (absen foto & QR) yang melewati masa simpan.
 *
 * Log absensinya TIDAK dihapus: hanya file foto, lalu photo_path dikosongkan.
 * Log yang masih 'pending_review' tidak disentuh, berapa pun umurnya, karena
 * supervisor masih butuh fotonya untuk memutuskan.
 *
 * Umur diatur lewat setting attendance.photo_retention_days (0 = nonaktif).
 * Dijadwalkan harian (lihat routes/console.php). Penghapusan tidak bisa
 * dibatalkan: jalankan --dry-run dulu sebelum mengaktifkan.
 */
class PruneAttendancePhotos extends Command
{
    protected $signature = 'attendance:prune-photos
        {--days= : Timpa masa simpan (hari) dari setting}
        {--dry-run : Hanya hitung jumlah foto yang akan dihapus}';

    protected $description = 'Hapus file foto absen yang lebih tua dari masa simpan (log absensi tetap ada).';

    private const CHUNK = 200;

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) Setting::value('attendance.photo_retention_days', 0);

        if ($days <= 0) {
            $this->info('Retensi foto absen nonaktif (0 hari = simpan selamanya).');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        $query = AttendanceLog::query()
            ->whereNotNull('photo_path')
            ->where('photo_path', '!=', '')
            ->where('status_sync', '!=', 'pending_review')
            ->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $count = (clone $query)->count();
            $this->info("Dry run: {$count} foto absen sebelum {$cutoff->toDateTimeString()} ({$days} hari) akan dihapus.");

            return self::SUCCESS;
        }

        $deleted = 0;
        $missing = 0;
        $failed = 0;

        $query->select(['id', 'photo_path'])->chunkById(self::CHUNK, function ($logs) use (&$deleted, &$missing, &$failed) {
            foreach ($logs as $log) {
                try {
                    // locate(): foto lama bisa masih di disk lokal walau disk aktif sudah S3.
                    $disk = AttendancePhoto::locate($log->photo_path);

                    if ($disk === null) {
                        $missing++;
                    } elseif ($disk->delete($log->photo_path)) {
                        $deleted++;
                    } else {
                        throw new \RuntimeException('Storage menolak penghapusan.');
                    }
                } catch (Throwable $e) {
                    // photo_path dibiarkan supaya dicoba lagi di siklus berikut.
                    $failed++;
                    Log::warning('Gagal menghapus foto absen.', [
                        'attendance_log_id' => $log->id,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                // Query langsung: updated_at log tidak ikut berubah.
                AttendanceLog::whereKey($log->id)->toBase()->update(['photo_path' => null]);
            }
        });

        if ($deleted + $missing + $failed > 0) {
            AuditLog::record('attendance.photos_pruned', changes: [
                'retention_days' => $days,
                'cutoff' => $cutoff->toDateTimeString(),
                'deleted' => $deleted,
                'missing' => $missing,
                'failed' => $failed,
            ], actorName: 'Sistem');
        }

        $this->info("Foto absen sebelum {$cutoff->toDateTimeString()}: {$deleted} dihapus, {$missing} sudah tidak ada, {$failed} gagal.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
