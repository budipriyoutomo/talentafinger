<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\TalentaBranchOutlet;
use App\Models\TalentaEmployee;
use App\Models\TalentaEmployeeSyncRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sync karyawan Tahap 2: talenta_employees -> employees.
 *
 * Aturan (TODO-Employee-Sync-Talenta.md §1):
 *  - Cocokkan: talenta_user_id -> barcode (= talenta_employee_id) -> employee_code.
 *  - Tak cocok & aktif -> buat Employee baru (D5). Tak cocok & resign -> dilewati.
 *  - Cocok -> field Talenta diperbarui otomatis (D7). talenta_employee_id (badgeno
 *    absensi) hanya diisi bila masih kosong; bila beda -> conflict.
 *  - Resign -> is_active=false (D6). Mesin tak disentuh.
 *  - Field lokal (biometric_id, device_privilege, allow_manual_attendance) tak
 *    pernah disentuh; outlet hanya diisi bila karyawan belum punya outlet (D11).
 *
 * Tiap baris diproses dalam transaksi sendiri: 1 baris error tak menggagalkan run.
 */
class TalentaEmployeeSyncService
{
    /** Field `employees` yang disalin dari Talenta tiap sync. */
    private const SYNCED_FIELDS = [
        'name', 'employee_code', 'email', 'job_position', 'organization_name',
        'branch_name', 'talenta_status', 'resign_date',
    ];

    /** @var array<string, string>|null branch_id Talenta -> outlet_id */
    private ?array $branchOutlets = null;

    public function sync(?User $by = null, ?TalentaEmployeeSyncRun $run = null): TalentaEmployeeSyncRun
    {
        $run ??= TalentaEmployeeSyncRun::create([
            'type' => TalentaEmployeeSyncRun::TYPE_SYNC,
            'triggered_by' => $by?->id,
        ]);
        $run->update(['status' => 'running', 'started_at' => now()]);
        $this->branchOutlets = null;

        $counts = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'conflict' => 0, 'error' => 0];

        try {
            // conflict & error ikut dicoba ulang: admin mungkin sudah membereskan data lokalnya.
            // chunkById (bukan chunk) karena sync_state baris berubah saat diproses.
            TalentaEmployee::whereIn('sync_state', ['pending', 'conflict', 'error'])
                ->chunkById(200, function ($rows) use (&$counts, $by, $run) {
                    foreach ($rows as $row) {
                        $outcome = $this->processRow($row, $by);
                        if (isset($counts[$outcome])) {
                            $counts[$outcome]++;
                        }
                    }
                    $run->update($this->countColumns($counts));
                });

            $run->update($this->countColumns($counts) + ['status' => 'success', 'finished_at' => now()]);
        } catch (Throwable $e) {
            Log::error('Talenta sync karyawan gagal', ['run_id' => $run->id, 'error' => $e->getMessage()]);
            $run->update($this->countColumns($counts) + [
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
        }

        return $run->refresh();
    }

    /**
     * Proses 1 baris. Return: created | updated | deactivated | unchanged | skipped | conflict | error.
     */
    public function processRow(TalentaEmployee $t, ?User $by = null): string
    {
        try {
            return DB::transaction(function () use ($t, $by) {
                [$employee, $conflict] = $this->match($t);

                if ($conflict !== null) {
                    $this->markConflict($t, $conflict, $employee);

                    return 'conflict';
                }

                if (! $employee) {
                    if ($t->isResigned()) {
                        // Sudah resign & tak pernah ada di lokal: tak perlu dibuat.
                        $this->markSynced($t, null, 'resigned');

                        return 'skipped';
                    }

                    $employee = $this->createEmployee($t, $by);
                    $this->markSynced($t, $employee, 'new');

                    return 'created';
                }

                $outcome = $this->updateEmployee($employee, $t, $by);
                $this->markSynced($t, $employee, $t->isResigned() ? 'resigned' : 'linked');

                return $outcome;
            });
        } catch (Throwable $e) {
            Log::warning('Talenta sync 1 karyawan gagal', ['talenta_user_id' => $t->talenta_user_id, 'error' => $e->getMessage()]);
            $t->update(['sync_state' => 'error', 'sync_error' => mb_substr($e->getMessage(), 0, 1000)]);

            return 'error';
        }
    }

    /**
     * Cari karyawan lokal untuk baris Talenta ini.
     *
     * @return array{0: ?Employee, 1: ?string}  [karyawan, alasan konflik]
     */
    public function match(TalentaEmployee $t): array
    {
        // 1. Sudah tertaut lewat kunci stabil.
        $employee = Employee::where('talenta_user_id', $t->talenta_user_id)->first();

        // 2. Badgeno sama (karyawan input manual / hasil import mesin).
        if (! $employee && $t->barcode !== null) {
            $employee = Employee::where('talenta_employee_id', $t->barcode)->first();
        }

        // 3. Employee ID Talenta sama, hanya ke karyawan yang belum tertaut.
        if (! $employee && $t->employee_code !== null) {
            $candidates = Employee::where('employee_code', $t->employee_code)->whereNull('talenta_user_id')->limit(2)->get();
            if ($candidates->count() > 1) {
                return [null, "Employee ID {$t->employee_code} dipakai lebih dari 1 karyawan lokal."];
            }
            $employee = $candidates->first();
        }

        if (! $employee) {
            // Barcode belum dipakai siapa pun (sudah dicek di langkah 2) -> aman dibuat baru.
            return [null, null];
        }

        if ($employee->talenta_user_id !== null && $employee->talenta_user_id !== $t->talenta_user_id) {
            return [$employee, "Karyawan lokal sudah tertaut ke user Talenta lain ({$employee->talenta_user_id})."];
        }

        if ($t->barcode !== null && $employee->talenta_employee_id !== null && $employee->talenta_employee_id !== $t->barcode) {
            return [$employee, "Talenta ID lokal ({$employee->talenta_employee_id}) beda dengan barcode Talenta ({$t->barcode})."];
        }

        if ($t->barcode !== null && $employee->talenta_employee_id === null && $this->barcodeTaken($t->barcode, $employee->id)) {
            return [$employee, "Barcode {$t->barcode} sudah dipakai karyawan lokal lain."];
        }

        return [$employee, null];
    }

    // ===== Aksi manual penyelesaian konflik =====

    /**
     * Tautkan baris Talenta ke karyawan lokal pilihan admin. $overwriteBadge:
     * timpa talenta_employee_id lokal dengan barcode Talenta (memengaruhi kirim absensi).
     */
    public function resolveLink(TalentaEmployee $t, Employee $employee, bool $overwriteBadge, ?User $by = null): Employee
    {
        return DB::transaction(function () use ($t, $employee, $overwriteBadge, $by) {
            if ($employee->talenta_user_id !== null && $employee->talenta_user_id !== $t->talenta_user_id) {
                throw new RuntimeException("{$employee->name} sudah tertaut ke user Talenta lain.");
            }

            // Lepas tautan lama bila user Talenta ini sebelumnya tertaut ke karyawan lain.
            Employee::where('talenta_user_id', $t->talenta_user_id)->where('id', '!=', $employee->id)
                ->update(['talenta_user_id' => null]);

            $before = $employee->only(['talenta_user_id', 'talenta_employee_id']);
            $employee->talenta_user_id = $t->talenta_user_id;

            if ($overwriteBadge && $t->barcode !== null && $employee->talenta_employee_id !== $t->barcode) {
                if ($this->barcodeTaken($t->barcode, $employee->id)) {
                    throw new RuntimeException("Barcode {$t->barcode} sudah dipakai karyawan lokal lain.");
                }
                $employee->talenta_employee_id = $t->barcode;
            }
            $employee->save();

            AuditLog::record('employee.talenta_linked', $employee,
                AuditLog::diff($before, $employee->only(['talenta_user_id', 'talenta_employee_id'])), $by);

            $this->updateEmployee($employee, $t, $by);
            $this->markSynced($t, $employee, $t->isResigned() ? 'resigned' : 'linked');

            return $employee;
        });
    }

    /** Paksa buat karyawan baru untuk baris Talenta ini (bukan duplikat). */
    public function resolveCreateNew(TalentaEmployee $t, ?User $by = null): Employee
    {
        return DB::transaction(function () use ($t, $by) {
            if (Employee::where('talenta_user_id', $t->talenta_user_id)->exists()) {
                throw new RuntimeException('User Talenta ini sudah tertaut ke karyawan lokal.');
            }
            if ($t->barcode !== null && $this->barcodeTaken($t->barcode)) {
                throw new RuntimeException("Barcode {$t->barcode} sudah dipakai karyawan lokal lain — tautkan saja, jangan buat baru.");
            }

            $employee = $this->createEmployee($t, $by);
            $this->markSynced($t, $employee, 'new');

            return $employee;
        });
    }

    /** Abaikan baris ini sampai datanya berubah di Talenta (Tahap 1 mengembalikannya ke pending). */
    public function ignore(TalentaEmployee $t, ?User $by = null): void
    {
        $t->update(['sync_state' => 'ignored']);
        AuditLog::record('talenta_employee.ignored', ['type' => 'talenta_employee', 'id' => $t->talenta_user_id, 'label' => $t->full_name], [], $by);
    }

    /**
     * D11 untuk karyawan yang SUDAH tertaut: isi outlet dari mapping cabang bagi
     * yang belum punya outlet. Dipanggil setelah admin menyimpan mapping, karena
     * sync biasa hanya memproses baris pending. Return jumlah karyawan yang diisi.
     */
    public function applyBranchOutlets(?User $by = null): int
    {
        $this->branchOutlets = null;
        $filled = 0;

        TalentaEmployee::whereNotNull('employee_id')
            ->whereNotNull('branch_id')
            ->whereHas('employee', fn ($q) => $q->whereDoesntHave('outlets'))
            ->with('employee')
            ->chunkById(200, function ($rows) use (&$filled, $by) {
                foreach ($rows as $t) {
                    if ($outletIds = $this->assignOutletIfEmpty($t->employee, $t)) {
                        AuditLog::record('employee.talenta_updated', $t->employee, [
                            'outlet_ids' => ['from' => [], 'to' => $outletIds],
                        ], $by);
                        $filled++;
                    }
                }
            });

        return $filled;
    }

    // ===== Internal =====

    private function createEmployee(TalentaEmployee $t, ?User $by): Employee
    {
        $employee = Employee::create($this->talentaAttributes($t) + [
            'talenta_user_id' => $t->talenta_user_id,
            // NULL bila Talenta belum punya barcode: absensinya tak terkirim sampai diisi.
            'talenta_employee_id' => $t->barcode,
            'is_active' => ! $t->isResigned(),
            'talenta_synced_at' => now(),
        ]);

        $outletIds = $this->assignOutletIfEmpty($employee, $t);

        AuditLog::record('employee.talenta_created', $employee, [
            'talenta_user_id' => $t->talenta_user_id,
            'talenta_employee_id' => $t->barcode,
            'outlet_ids' => $outletIds,
        ], $by);

        return $employee;
    }

    /** Return: updated | deactivated | unchanged. */
    private function updateEmployee(Employee $employee, TalentaEmployee $t, ?User $by): string
    {
        $wasResigned = $employee->talenta_status === TalentaEmployee::STATUS_RESIGNED;
        $before = $employee->only([...self::SYNCED_FIELDS, 'talenta_user_id', 'talenta_employee_id', 'is_active']);
        $before['resign_date'] = $employee->resign_date?->toDateString();

        $employee->fill($this->talentaAttributes($t));
        $employee->talenta_user_id = $t->talenta_user_id;
        if ($employee->talenta_employee_id === null && $t->barcode !== null) {
            $employee->talenta_employee_id = $t->barcode;
        }

        $deactivated = false;
        if ($t->isResigned()) {
            $deactivated = $employee->is_active;
            $employee->is_active = false;
        } elseif ($wasResigned) {
            // Aktif lagi di Talenta. Karyawan yang dinonaktifkan manual (bukan karena
            // resign) sengaja TIDAK diaktifkan ulang.
            $employee->is_active = true;
        }

        $after = $employee->only([...self::SYNCED_FIELDS, 'talenta_user_id', 'talenta_employee_id', 'is_active']);
        $after['resign_date'] = $employee->resign_date?->toDateString();
        $changes = AuditLog::diff($before, $after);

        $employee->talenta_synced_at = now();
        $employee->save();

        if ($outletIds = $this->assignOutletIfEmpty($employee, $t)) {
            $changes['outlet_ids'] = ['from' => [], 'to' => $outletIds];
        }

        if ($changes === []) {
            return 'unchanged';
        }

        AuditLog::record($deactivated ? 'employee.talenta_deactivated' : 'employee.talenta_updated', $employee, $changes, $by);

        return $deactivated ? 'deactivated' : 'updated';
    }

    /** Kolom `employees` yang bersumber dari Talenta (tanpa badgeno & kunci). */
    private function talentaAttributes(TalentaEmployee $t): array
    {
        return array_filter([
            'name' => $t->full_name,
            'employee_code' => $t->employee_code,
            'email' => $t->email,
            'job_position' => $t->job_position,
            'organization_name' => $t->organization_name,
            'branch_name' => $t->branch_name,
            'talenta_status' => $t->status,
            'resign_date' => $t->resign_date?->toDateString(),
        ], fn ($v, $k) => $v !== null || in_array($k, ['resign_date', 'email'], true), ARRAY_FILTER_USE_BOTH);
    }

    /** D11: isi outlet dari mapping cabang HANYA bila karyawan belum punya outlet. */
    private function assignOutletIfEmpty(Employee $employee, TalentaEmployee $t): array
    {
        if ($t->branch_id === null) {
            return [];
        }

        $this->branchOutlets ??= TalentaBranchOutlet::pluck('outlet_id', 'talenta_branch_id')->all();
        $outletId = $this->branchOutlets[$t->branch_id] ?? null;

        if (! $outletId || $employee->outlets()->exists()) {
            return [];
        }

        $employee->outlets()->attach($outletId);

        return [$outletId];
    }

    private function barcodeTaken(string $barcode, ?string $exceptEmployeeId = null): bool
    {
        return Employee::where('talenta_employee_id', $barcode)
            ->when($exceptEmployeeId, fn ($q) => $q->where('id', '!=', $exceptEmployeeId))
            ->exists();
    }

    private function markSynced(TalentaEmployee $t, ?Employee $employee, string $matchStatus): void
    {
        $t->update([
            'employee_id' => $employee?->id,
            'match_status' => $matchStatus,
            'sync_state' => 'synced',
            'sync_error' => null,
            'synced_at' => now(),
        ]);
    }

    private function markConflict(TalentaEmployee $t, string $reason, ?Employee $candidate): void
    {
        $t->update([
            // Kandidat disimpan supaya UI bisa menampilkan perbandingan.
            'employee_id' => $candidate?->id,
            'match_status' => 'conflict',
            'sync_state' => 'conflict',
            'sync_error' => $reason,
        ]);
    }

    private function countColumns(array $counts): array
    {
        return [
            'count_created' => $counts['created'],
            'count_updated' => $counts['updated'],
            'count_deactivated' => $counts['deactivated'],
            'count_conflict' => $counts['conflict'],
            'count_error' => $counts['error'],
        ];
    }
}
