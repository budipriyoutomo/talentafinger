<?php

namespace App\Jobs;

use App\Models\Machine;
use App\Services\ZkSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Probe jalur TCP 4370 (server -> mesin) semua mesin aktif ber-IP, lalu simpan
 * hasilnya ke kolom tcp_*.
 *
 * Sengaja dijalankan lewat QUEUE, bukan langsung di command terjadwal: container
 * `scheduler` tidak berada di namespace jaringan VPN, sehingga dari sana semua
 * mesin terbaca "tidak terjangkau". Worker `queue` memakai network_mode
 * service:vpn, sama seperti tombol probe manual di container `app`.
 */
class ProbeMachinesTcp implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /** Status diperbarui lagi di siklus berikutnya, jadi tak perlu retry. */
    public $tries = 1;

    /**
     * WAJIB di bawah --timeout worker & retry_after koneksi queue (keduanya 90
     * detik). Melewati retry_after membuat job bisa diambil ulang sebelum selesai.
     */
    public $timeout = 85;

    /**
     * Batas waktu loop. Mesin yang belum sempat diprobe menunggu siklus berikut;
     * urutan "paling lama tak dicek dulu" membuatnya tetap bergiliran.
     */
    public const TIME_BUDGET_SECONDS = 60;

    /** Lock unik dilepas saat job selesai; ini hanya batas bila worker mati di tengah jalan. */
    public $uniqueFor = 300;

    /** @return array{ok:int, down:int, skipped:int} */
    public function handle(ZkSyncService $zk): array
    {
        // Hanya mesin aktif yang punya IP LAN — tanpa IP, jalur 4370 tak berlaku.
        $machines = Machine::where('is_active', true)
            ->whereNotNull('ip_address')
            // Belum pernah dicek dulu, lalu yang paling lama. Ditulis eksplisit
            // karena PostgreSQL dan SQLite berbeda menaruh NULL saat ORDER BY ASC.
            ->orderByRaw('(tcp_checked_at IS NULL) DESC')
            ->orderBy('tcp_checked_at')
            ->get();

        $started = microtime(true);
        $ok = 0;
        $down = 0;
        $skipped = 0;

        foreach ($machines as $machine) {
            if (microtime(true) - $started >= self::TIME_BUDGET_SECONDS) {
                $skipped = $machines->count() - $ok - $down;
                break;
            }

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

        if ($skipped > 0) {
            Log::warning('Probe TCP 4370: batas waktu tercapai, sisa mesin menunggu siklus berikut.', [
                'probed' => $ok + $down,
                'skipped' => $skipped,
            ]);
        }

        return ['ok' => $ok, 'down' => $down, 'skipped' => $skipped];
    }
}
