<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;
use App\Models\Employee;
use App\Models\FingerprintDistributeJob;
use App\Models\Machine;
use App\Services\FingerprintMasterService;

/**
 * Sebar template sidik jari DARI DB ke banyak mesin untuk banyak karyawan,
 * di background. Progres & hasil per karyawan ditulis ke baris
 * FingerprintDistributeJob yang dipolling frontend.
 */
class DistributeFingerprintsBulk implements ShouldQueue
{
    use Queueable;

    public $tries = 1;
    public $timeout = 3600;

    public function __construct(
        private string $jobId,
    ) {}

    public function handle(FingerprintMasterService $svc): void
    {
        $job = FingerprintDistributeJob::find($this->jobId);
        if (! $job) {
            return;
        }

        $targets = Machine::whereIn('id', $job->target_machine_ids)->get();
        if ($targets->isEmpty()) {
            $job->update(['status' => 'failed', 'error' => 'Mesin tujuan tidak ditemukan saat job dijalankan.']);
            return;
        }

        $job->update(['status' => 'processing']);

        $items = [];
        $okCount = 0;

        foreach ($job->employee_ids as $employeeId) {
            $employee = Employee::find($employeeId);

            if (! $employee) {
                $items[] = ['employee_id' => $employeeId, 'name' => null, 'ok' => false,
                    'error' => 'Karyawan tidak ditemukan.', 'results' => []];
            } else {
                $results = $targets->map(fn ($m) => $svc->distributeToMachine($employee, $m))->all();
                $allOk = ! empty($results) && collect($results)->every(fn ($r) => $r['ok'] ?? false);
                if ($allOk) {
                    $okCount++;
                }
                $items[] = [
                    'employee_id' => $employeeId,
                    'name' => $employee->name,
                    'ok' => $allOk,
                    'results' => $results,
                ];
            }

            // Progres live per karyawan.
            $job->update(['progress_done' => count($items), 'items' => $items]);
        }

        $job->update([
            'status' => 'done',
            'items' => $items,
            'summary' => [
                'employees' => count($job->employee_ids),
                'ok' => $okCount,
                'failed' => count($job->employee_ids) - $okCount,
            ],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        FingerprintDistributeJob::where('id', $this->jobId)->update([
            'status' => 'failed',
            'error' => $exception->getMessage(),
        ]);
    }
}
