<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\AttendanceSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Pengiriman AUTO absensi ke Talenta. Dijadwalkan tiap menit (lihat
 * routes/console.php) dan menahan diri sesuai pengaturan:
 *   - attendance.auto_send                : master on/off
 *   - attendance.auto_send_interval       : jeda antar kirim (menit)
 *   - attendance.auto_send_window_start/end : jam aktif (HH:MM), opsional
 *   - attendance.auto_send_include_failed : ikut kirim ulang yang gagal
 *
 * Pengiriman MANUAL tetap lewat tombol di aplikasi (AttendanceController).
 */
class AutoSendAttendance extends Command
{
    protected $signature = 'attendance:auto-send {--force : Abaikan jadwal & interval, kirim sekarang}';

    protected $description = 'Kirim absensi pending ke Talenta sesuai pengaturan auto-send.';

    private const LAST_RUN_KEY = 'attendance.auto_send.last_run';

    public function handle(AttendanceSyncService $sync): int
    {
        $force = (bool) $this->option('force');

        if (! $force) {
            if (! Setting::value('attendance.auto_send')) {
                $this->info('Auto-send nonaktif.');
                return self::SUCCESS;
            }
            if (! $this->withinWindow()) {
                $this->info('Di luar jam auto-send.');
                return self::SUCCESS;
            }
            if (! $this->intervalElapsed()) {
                $this->info('Belum waktunya kirim (interval belum tercapai).');
                return self::SUCCESS;
            }
        }

        $includeFailed = (bool) Setting::value('attendance.auto_send_include_failed', false);
        $result = $sync->sendPending($includeFailed);

        Cache::put(self::LAST_RUN_KEY, now()->toIso8601String());

        $this->info($result['message'] ?? 'Selesai.');

        return self::SUCCESS;
    }

    /** Interval (menit) sejak kirim terakhir sudah terlewati? */
    private function intervalElapsed(): bool
    {
        $interval = max(1, (int) Setting::value('attendance.auto_send_interval', 15));
        $last = Cache::get(self::LAST_RUN_KEY);

        return ! $last || Carbon::parse($last)->addMinutes($interval)->isPast();
    }

    /** Sekarang berada di dalam jam aktif? Tanpa window = 24 jam. */
    private function withinWindow(): bool
    {
        $start = Setting::value('attendance.auto_send_window_start');
        $end = Setting::value('attendance.auto_send_window_end');

        if (! $start || ! $end) {
            return true;
        }

        // Bandingkan string "HH:MM" (format 24 jam, dalam hari yang sama).
        $now = now()->format('H:i');

        return $now >= $start && $now <= $end;
    }
}
