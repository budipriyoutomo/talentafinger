<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\TalentaEmployeeSyncRun;
use App\Services\TalentaEmployeeSyncRunner;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Sync karyawan Talenta: Tahap 1 (Talenta -> talenta_employees) lalu Tahap 2
 * (talenta_employees -> employees). Dijadwalkan tiap menit (routes/console.php)
 * dan menahan diri sesuai pengaturan:
 *   - talenta.employee_sync          : master on/off
 *   - talenta.employee_sync_interval : jeda antar siklus (menit)
 *
 * Interval dihitung dari run pull terakhir (termasuk yang dipicu tombol manual),
 * jadi klik manual ikut "mereset" jadwal. Dikerjakan inline (tanpa queue).
 */
class SyncTalentaEmployees extends Command
{
    protected $signature = 'talenta:sync-employees
        {--pull-only : Hanya Tahap 1 (tarik ke tabel sementara)}
        {--sync-only : Hanya Tahap 2 (tabel sementara -> employees)}
        {--force : Abaikan pengaturan on/off & interval}';

    protected $description = 'Tarik data karyawan dari Talenta lalu sinkronkan ke tabel employees.';

    public function handle(TalentaEmployeeSyncRunner $runner): int
    {
        if ($this->option('pull-only') && $this->option('sync-only')) {
            $this->error('--pull-only dan --sync-only tidak bisa dipakai bersamaan.');

            return self::INVALID;
        }

        $mode = match (true) {
            (bool) $this->option('pull-only') => 'pull',
            (bool) $this->option('sync-only') => 'sync',
            default => 'both',
        };

        if (! $this->option('force')) {
            if (! Setting::value('talenta.employee_sync')) {
                $this->info('Auto sync karyawan Talenta nonaktif.');

                return self::SUCCESS;
            }
            if (! $this->intervalElapsed()) {
                $this->info('Belum waktunya sync karyawan (interval belum tercapai).');

                return self::SUCCESS;
            }
        }

        try {
            $runs = $runner->runNow($mode);
        } catch (RuntimeException $e) {
            // Mis. run lain masih berjalan: bukan error bagi scheduler.
            $this->warn($e->getMessage());

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($runs as $run) {
            $failed = $failed || $run->status !== 'success';
            $this->line($run->type === TalentaEmployeeSyncRun::TYPE_PULL
                ? "Pull [{$run->status}]: {$run->total_fetched} diambil, {$run->count_changed} baru/berubah."
                : "Sync [{$run->status}]: {$run->count_created} dibuat, {$run->count_updated} diupdate, "
                    . "{$run->count_deactivated} dinonaktifkan, {$run->count_conflict} konflik, {$run->count_error} error.");
            if ($run->error_message) {
                $this->error("  {$run->error_message}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function intervalElapsed(): bool
    {
        $interval = max(1, (int) Setting::value('talenta.employee_sync_interval', 1440));
        $last = TalentaEmployeeSyncRun::where('type', TalentaEmployeeSyncRun::TYPE_PULL)->latest()->value('created_at');

        return ! $last || $last->copy()->addMinutes($interval)->isPast();
    }
}
