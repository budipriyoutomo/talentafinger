<?php

namespace App\Console\Commands;

use App\Models\Machine;
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
 */
class ProbeMachineTcp extends Command
{
    protected $signature = 'machine:probe-tcp {--force : Abaikan jadwal & interval, probe sekarang}';

    protected $description = 'Probe status jalur TCP 4370 mesin aktif ber-IP dan simpan hasilnya.';

    private const LAST_RUN_KEY = 'machine.tcp_probe.last_run';

    public function handle(ZkSyncService $zk): int
    {
        $force = (bool) $this->option('force');

        if (! $force) {
            if (! Setting::value('machine.tcp_probe')) {
                $this->info('Probe TCP 4370 nonaktif.');
                return self::SUCCESS;
            }
            if (! $this->intervalElapsed()) {
                $this->info('Belum waktunya probe (interval belum tercapai).');
                return self::SUCCESS;
            }
        }

        // Hanya mesin aktif yang punya IP LAN — tanpa IP, jalur 4370 tak berlaku.
        $machines = Machine::where('is_active', true)
            ->whereNotNull('ip_address')
            ->get();

        $ok = 0;
        $down = 0;
        foreach ($machines as $machine) {
            $res = $zk->ping($machine);
            $online = (bool) ($res['ok'] ?? false);

            $machine->update([
                'tcp_checked_at' => now(),
                'tcp_online' => $online,
                'tcp_latency_ms' => $online ? ($res['latency_ms'] ?? null) : null,
                'tcp_error' => $online ? null : ($res['error'] ?? 'Tidak terjangkau'),
            ]);

            $online ? $ok++ : $down++;
        }

        Cache::put(self::LAST_RUN_KEY, now()->toIso8601String());

        $this->info("Probe TCP 4370: {$ok} ready, {$down} tidak terjangkau.");

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
