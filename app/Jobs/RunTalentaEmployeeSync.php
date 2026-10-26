<?php

namespace App\Jobs;

use App\Models\TalentaEmployeeSyncRun;
use App\Models\User;
use App\Services\TalentaEmployeeSyncRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Kerjakan run sync karyawan Talenta (pull dan/atau sync) di background.
 * Baris run sudah dibuat TalentaEmployeeSyncRunner::start() supaya UI bisa
 * polling sejak tombol diklik.
 */
class RunTalentaEmployeeSync implements ShouldQueue
{
    use Queueable;

    // Tak di-retry otomatis: pull sudah retry per request, dan run yang gagal
    // terlihat di riwayat untuk diulang manual.
    public $tries = 1;
    public $timeout = 3600;

    /** @param  string[]  $runIds */
    public function __construct(
        private array $runIds,
        private ?int $userId = null,
    ) {}

    public function handle(TalentaEmployeeSyncRunner $runner): void
    {
        $runs = TalentaEmployeeSyncRun::whereIn('id', $this->runIds)->get()
            // Urutan wajib pull dulu, baru sync.
            ->sortBy(fn ($r) => $r->type === TalentaEmployeeSyncRun::TYPE_PULL ? 0 : 1)
            ->values()->all();

        if ($runs === []) {
            return;
        }

        $runner->execute($runs, $this->userId ? User::find($this->userId) : null);
    }

    public function failed(Throwable $e): void
    {
        TalentaEmployeeSyncRun::whereIn('id', $this->runIds)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'error_message' => mb_substr('Job gagal: ' . $e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
    }
}
