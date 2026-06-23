<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;
use App\Models\FingerprintDeleteJob;
use App\Models\Machine;
use App\Services\ZkSyncService;

/**
 * Hapus banyak user (beserta sidik jarinya) dari satu mesin via TCP 4370,
 * di background. Progres & hasil per-PIN ditulis ke baris FingerprintDeleteJob
 * yang dipolling frontend. Permanen di perangkat; tak menyentuh DB.
 */
class DeleteFingerprintsBulk implements ShouldQueue
{
    use Queueable;

    public $tries = 1;
    public $timeout = 3600;

    public function __construct(
        private string $jobId,
    ) {}

    public function handle(ZkSyncService $zk): void
    {
        $job = FingerprintDeleteJob::find($this->jobId);
        if (! $job) {
            return;
        }

        $machine = Machine::find($job->machine_id);
        if (! $machine) {
            $job->update(['status' => 'failed', 'error' => 'Mesin tidak ditemukan saat job dijalankan.']);
            return;
        }

        $job->update(['status' => 'processing']);

        $items = [];
        $okCount = 0;

        foreach ($job->pins as $pin) {
            $res = $zk->delete($machine, (string) $pin);
            $ok = ($res['ok'] ?? false) && ($res['deleted'] ?? false);
            if ($ok) {
                $okCount++;
            }

            $items[] = [
                'pin' => $pin,
                'ok' => $ok,
                'deleted' => $res['deleted'] ?? false,
                'error' => $res['error'] ?? null,
            ];

            // Progres live per PIN.
            $job->update(['progress_done' => count($items), 'items' => $items]);
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
        FingerprintDeleteJob::where('id', $this->jobId)->update([
            'status' => 'failed',
            'error' => $exception->getMessage(),
        ]);
    }
}
