<?php

namespace App\Services;

use App\Jobs\DeleteFingerprintsBulk;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\FingerprintDeleteJob;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Tombol "Hapus dari Mesin" (D6): hapus user + sidik jari karyawan (biasanya
 * yang resign) dari mesin pilihan via TCP 4370. Selalu MANUAL, tak pernah
 * dipicu otomatis oleh sync karyawan Talenta.
 *
 * Memakai ulang FingerprintDeleteJob + DeleteFingerprintsBulk: 1 job per mesin.
 * Otorisasi (permission fingerprint.delete & scope mesin) dicek di controller.
 */
class EmployeeMachineRemovalService
{
    /**
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, Machine>  $machines
     * @return array{jobs: FingerprintDeleteJob[], skipped_employees: string[], skipped_machines: string[]}
     */
    public function queue(Collection $employees, Collection $machines, ?User $by = null): array
    {
        // PIN di mesin = biometric_id. Karyawan tanpa PIN tak pernah terdaftar di mesin.
        $withPin = $employees->filter(fn (Employee $e) => filled($e->biometric_id));
        $pins = $withPin->pluck('biometric_id')->map(fn ($p) => (string) $p)->unique()->values()->all();

        if ($pins === []) {
            throw new RuntimeException('Tidak ada karyawan terpilih yang punya Biometric ID (PIN mesin).');
        }

        $jobs = [];
        $skippedMachines = [];

        foreach ($machines as $machine) {
            if (! $machine->ip_address) {
                $skippedMachines[] = $machine->name;
                continue;
            }

            $job = FingerprintDeleteJob::create([
                'machine_id' => $machine->id,
                'pins' => $pins,
                'status' => 'queued',
                'progress_total' => count($pins),
                'progress_done' => 0,
            ]);
            DeleteFingerprintsBulk::dispatch($job->id);
            $jobs[] = $job;
        }

        if ($jobs === []) {
            throw new RuntimeException('Tidak ada mesin terpilih yang punya IP.');
        }

        // Ditandai saat permintaan dikirim; hasil per PIN tetap ada di FingerprintDeleteJob.
        Employee::whereIn('id', $withPin->pluck('id'))->update(['removed_from_machines_at' => now()]);

        AuditLog::record('employee.removed_from_machines', null, [
            'employee_ids' => $withPin->pluck('id')->values()->all(),
            'pins' => $pins,
            'machine_ids' => collect($jobs)->pluck('machine_id')->all(),
            'job_ids' => collect($jobs)->pluck('id')->all(),
        ], $by);

        return [
            'jobs' => $jobs,
            'skipped_employees' => $employees->diff($withPin)->pluck('name')->values()->all(),
            'skipped_machines' => $skippedMachines,
        ];
    }
}
