<?php

namespace App\Services;

use App\Jobs\RunTalentaEmployeeSync;
use App\Models\TalentaEmployeeSyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pintu masuk tunggal sync karyawan Talenta, dipakai tombol UI (via queue) dan
 * command/scheduler (inline). Menjamin hanya 1 rangkaian pull/sync berjalan:
 *   1. start() menolak bila masih ada run queued/running yang belum basi;
 *   2. execute() memegang Cache::lock selama bekerja.
 *
 * Mode: both (Tahap 1 lalu Tahap 2) | pull (Tahap 1 saja) | sync (Tahap 2 saja).
 */
class TalentaEmployeeSyncRunner
{
    public const MODES = ['both', 'pull', 'sync'];

    private const LOCK_KEY = 'talenta-employee-sync';
    private const LOCK_SECONDS = 7200;

    /** Run queued/running lebih tua dari ini dianggap mati (worker crash dsb.). */
    private const STALE_MINUTES = 120;

    public function __construct(
        private TalentaEmployeePullService $pull,
        private TalentaEmployeeSyncService $sync,
    ) {}

    /** Buat run lalu kerjakan di queue. Untuk tombol UI. */
    public function dispatch(string $mode, ?User $by = null): array
    {
        $runs = $this->start($mode, $by);
        RunTalentaEmployeeSync::dispatch(array_map(fn ($r) => $r->id, $runs), $by?->id);

        return $runs;
    }

    /** Buat run lalu kerjakan langsung (blocking). Untuk command/scheduler. */
    public function runNow(string $mode, ?User $by = null): array
    {
        $runs = $this->start($mode, $by);
        $this->execute($runs, $by);

        return array_map(fn ($r) => $r->refresh(), $runs);
    }

    /**
     * Buat baris run berstatus queued supaya UI bisa langsung polling.
     *
     * @return TalentaEmployeeSyncRun[]  urut pull lalu sync
     */
    public function start(string $mode, ?User $by = null): array
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Mode sync tidak dikenal: {$mode}");
        }

        $this->failStaleRuns();

        if ($active = $this->activeRun()) {
            throw new RuntimeException("Sync karyawan Talenta masih berjalan ({$active->type}, mulai {$active->created_at}).");
        }

        $types = match ($mode) {
            'both' => [TalentaEmployeeSyncRun::TYPE_PULL, TalentaEmployeeSyncRun::TYPE_SYNC],
            'pull' => [TalentaEmployeeSyncRun::TYPE_PULL],
            'sync' => [TalentaEmployeeSyncRun::TYPE_SYNC],
        };

        return array_map(fn ($type) => TalentaEmployeeSyncRun::create([
            'type' => $type,
            'triggered_by' => $by?->id,
            'status' => 'queued',
        ]), $types);
    }

    /**
     * Kerjakan run hasil start(). Tahap 2 dilewati bila Tahap 1 di rangkaian
     * yang sama gagal: data tabel sementara belum lengkap/terbaru.
     *
     * @param  TalentaEmployeeSyncRun[]  $runs
     */
    public function execute(array $runs, ?User $by = null): void
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            foreach ($runs as $run) {
                $this->fail($run, 'Dilewati: sync karyawan Talenta lain sedang berjalan.');
            }

            return;
        }

        try {
            $pullFailed = false;

            foreach ($runs as $run) {
                if ($run->type === TalentaEmployeeSyncRun::TYPE_PULL) {
                    $pullFailed = $this->pull->pull($by, $run)->status !== 'success';
                    continue;
                }

                if ($pullFailed) {
                    $this->fail($run, 'Dilewati: tarik data dari Talenta gagal, lihat run pull.');
                    continue;
                }

                $this->sync->sync($by, $run);
            }
        } finally {
            $lock->release();
        }
    }

    public function activeRun(): ?TalentaEmployeeSyncRun
    {
        return TalentaEmployeeSyncRun::whereIn('status', ['queued', 'running'])->latest()->first();
    }

    private function failStaleRuns(): void
    {
        TalentaEmployeeSyncRun::whereIn('status', ['queued', 'running'])
            ->where('created_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update([
                'status' => 'failed',
                'error_message' => 'Kedaluwarsa: run tak selesai dalam ' . self::STALE_MINUTES . ' menit (worker berhenti?).',
                'finished_at' => now(),
            ]);
    }

    private function fail(TalentaEmployeeSyncRun $run, string $message): void
    {
        $run->update(['status' => 'failed', 'error_message' => $message, 'finished_at' => now()]);
    }
}
