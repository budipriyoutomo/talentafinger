<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Models\Setting;
use App\Services\DeviceCommandService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Sinkronisasi jam mesin secara AUTO. Dijadwalkan tiap menit (lihat
 * routes/console.php) dan menahan diri sesuai pengaturan:
 *   - machine.auto_sync_time          : master on/off
 *   - machine.auto_sync_time_interval : jeda antar siklus (menit)
 *
 * Tiap siklus mengantrekan perintah SET OPTIONS DateTime= untuk mesin yang
 * aktif dan online (polling baru-baru ini). Antrean tidak menumpuk karena
 * memakai queueSyncTimeIfAbsent (skip bila masih ada sync_time pending/sent).
 *
 * Sinkronisasi MANUAL tetap lewat tombol "Sync Time" di halaman Machines.
 */
class AutoSyncMachineTime extends Command
{
    protected $signature = 'machine:auto-sync-time {--force : Abaikan jadwal & interval, sinkron sekarang}';

    protected $description = 'Antrekan sinkronisasi jam ke mesin aktif & online sesuai pengaturan auto-sync.';

    private const LAST_RUN_KEY = 'machine.auto_sync_time.last_run';

    public function handle(DeviceCommandService $commands): int
    {
        $force = (bool) $this->option('force');

        if (! $force) {
            if (! Setting::value('machine.auto_sync_time')) {
                $this->info('Auto-sync jam mesin nonaktif.');
                return self::SUCCESS;
            }
            if (! $this->intervalElapsed()) {
                $this->info('Belum waktunya sinkron (interval belum tercapai).');
                return self::SUCCESS;
            }
        }

        $machines = Machine::where('is_active', true)->get();

        $queued = 0;
        $skipped = 0;
        foreach ($machines as $machine) {
            // Mesin offline dilewati: percuma menumpuk perintah yang baru
            // dieksekusi entah kapan. Saat mesin kembali online, siklus
            // berikutnya akan mengantrekannya.
            if (! $force && ! $machine->isOnline()) {
                $skipped++;
                continue;
            }

            if ($commands->queueSyncTimeIfAbsent($machine)) {
                $queued++;
            } else {
                $skipped++;
            }
        }

        Cache::put(self::LAST_RUN_KEY, now()->toIso8601String());

        $this->info("Auto-sync jam mesin: {$queued} diantrekan, {$skipped} dilewati.");

        return self::SUCCESS;
    }

    /** Interval (menit) sejak siklus terakhir sudah terlewati? */
    private function intervalElapsed(): bool
    {
        $interval = max(1, (int) Setting::value('machine.auto_sync_time_interval', 1440));
        $last = Cache::get(self::LAST_RUN_KEY);

        return ! $last || Carbon::parse($last)->addMinutes($interval)->isPast();
    }
}
