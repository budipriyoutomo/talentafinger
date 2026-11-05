<?php

namespace App\Console\Commands;

use App\Jobs\ProbeMachinesTcp;
use App\Models\Setting;
use App\Services\ZkSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Probe kesehatan jalur TCP 4370 (server -> mesin, via pyzk) secara AUTO.
 * Dijadwalkan tiap menit (lihat routes/console.php) dan menahan diri sesuai
 * pengaturan:
 *   - machine.tcp_probe          : master on/off
 *   - machine.tcp_probe_interval : jeda antar siklus (menit)
 *
 * Berbeda dari status ADMS (last_seen_at, mesin -> server): jalur ini menguji
 * apakah SERVER bisa membuka koneksi ke port 4370 mesin — dipakai sidik jari.
 * Mesin bisa "online" via ADMS tapi 4370-nya tak terjangkau (firewall/subnet).
 * Hasil disimpan di kolom tcp_* dan ditampilkan sebagai badge terpisah di UI.
 *
 * Command ini hanya MENGANTREKAN job ProbeMachinesTcp. Probe-nya sendiri harus
 * jalan di worker `queue` (di dalam VPN); container `scheduler` tidak bisa
 * menjangkau mesin. Lihat ProbeMachinesTcp.
 */
class ProbeMachineTcp extends Command
{
    protected $signature = 'machine:probe-tcp
        {--force : Abaikan pengaturan on/off & interval}
        {--sync : Probe langsung di proses ini, bukan lewat queue (untuk diagnosis; jalankan di container app)}';

    protected $description = 'Antrekan probe status jalur TCP 4370 mesin aktif ber-IP.';

    private const LAST_RUN_KEY = 'machine.tcp_probe.last_run';

    public function handle(ZkSyncService $zk): int
    {
        if (! $this->option('force')) {
            if (! Setting::value('machine.tcp_probe')) {
                $this->info('Probe TCP 4370 nonaktif.');
                return self::SUCCESS;
            }
            if (! $this->intervalElapsed()) {
                $this->info('Belum waktunya probe (interval belum tercapai).');
                return self::SUCCESS;
            }
        }

        Cache::put(self::LAST_RUN_KEY, now()->toIso8601String());

        if ($this->option('sync')) {
            $res = (new ProbeMachinesTcp)->handle($zk);
            $this->info("Probe TCP 4370: {$res['ok']} ready, {$res['down']} tidak terjangkau, {$res['skipped']} menunggu siklus berikut.");

            return self::SUCCESS;
        }

        // ShouldBeUnique: bila job sebelumnya masih antre/berjalan, dispatch ini diabaikan.
        ProbeMachinesTcp::dispatch();
        $this->info('Probe TCP 4370 diantrekan ke worker queue.');

        return self::SUCCESS;
    }

    /** Interval (menit) sejak siklus terakhir sudah terlewati? */
    private function intervalElapsed(): bool
    {
        $interval = max(1, (int) Setting::value('machine.tcp_probe_interval', 5));
        $last = Cache::get(self::LAST_RUN_KEY);

        return ! $last || Carbon::parse($last)->addMinutes($interval)->isPast();
    }
}
