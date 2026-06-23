<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;
use App\Models\FingerprintSyncJob;
use App\Models\Machine;
use App\Services\ZkSyncService;

/**
 * Sebar sidik jari banyak PIN dari 1 mesin sumber ke banyak mesin tujuan,
 * berjalan di background. Progres & hasil ditulis ke baris FingerprintSyncJob
 * yang dipolling frontend.
 *
 * Tiap PIN = beberapa invokasi pyzk (tarik + push per mesin), bisa lama —
 * makanya tidak boleh blokir HTTP request.
 */
class SyncFingerprintsBulk implements ShouldQueue
{
    use Queueable;

    // Batch utuh; jangan retry otomatis (kegagalan per-PIN sudah ditangani
    // di dalam handle dan dicatat per item).
    public $tries = 1;

    // pyzk butuh waktu (timeout Process 120s per langkah) × banyak PIN.
    public $timeout = 3600;

    // Sengaja dibiarkan di queue 'default' supaya langsung diproses oleh
    // worker bawaan (composer dev → queue:listen tanpa --queue). Untuk produksi,
    // pertimbangkan queue khusus + worker terpisah agar job panjang ini tidak
    // memblokir job lain.
    public function __construct(
        private string $jobId,
    ) {}

    public function handle(ZkSyncService $zk): void
    {
        $job = FingerprintSyncJob::find($this->jobId);
        if (! $job) {
            return;
        }

        $source = Machine::find($job->source_machine_id);
        $targets = Machine::whereIn('id', $job->target_machine_ids)
            ->where('id', '!=', $job->source_machine_id)
            ->get()
            ->all();

        if (! $source || empty($targets)) {
            $job->update([
                'status' => 'failed',
                'error' => 'Mesin sumber/tujuan tidak ditemukan saat job dijalankan.',
            ]);
            return;
        }

        $job->update(['status' => 'processing']);

        $items = [];
        $okCount = 0;

        foreach ($job->pins as $pin) {
            $res = $zk->syncPin($source, $targets, (string) $pin);

            if ($res['ok'] ?? false) {
                // Sukses penuh = semua mesin tujuan terpasang.
                $allOk = ! empty($res['results'])
                    && collect($res['results'])->every(fn ($r) => $r['ok'] ?? false);
                if ($allOk) {
                    $okCount++;
                }
                $items[] = [
                    'pin' => $pin,
                    'name' => $res['name'] ?? null,
                    'ok' => true,
                    'pulled_fingers' => $res['pulled_fingers'] ?? 0,
                    'results' => $res['results'],
                ];
            } else {
                $items[] = [
                    'pin' => $pin,
                    'name' => null,
                    'ok' => false,
                    'pulled_fingers' => 0,
                    'error' => $res['error'] ?? 'Gagal menarik template dari mesin sumber',
                    'results' => [],
                ];
            }

            // Tulis progres setiap PIN supaya frontend bisa menampilkannya live.
            $job->update([
                'progress_done' => count($items),
                'items' => $items,
            ]);
        }

        $job->update([
            'status' => 'done',
            'items' => $items,
            'summary' => [
                'pins' => count($job->pins),
                'ok' => $okCount,
                'failed' => count($job->pins) - $okCount,
            ],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        FingerprintSyncJob::where('id', $this->jobId)->update([
            'status' => 'failed',
            'error' => $exception->getMessage(),
        ]);
    }
}
