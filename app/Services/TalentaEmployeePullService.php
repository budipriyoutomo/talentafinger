<?php

namespace App\Services;

use App\Models\TalentaEmployee;
use App\Models\TalentaEmployeeSyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sync karyawan Tahap 1: Talenta API -> talenta_employees (tabel sementara).
 *
 * Tidak menyentuh tabel `employees` sama sekali; itu tugas
 * TalentaEmployeeSyncService (Tahap 2). Baris yang baru / datanya berubah
 * ditandai sync_state = pending supaya diproses Tahap 2.
 */
class TalentaEmployeePullService
{
    public function __construct(
        private MekariTalentaService $talenta,
    ) {}

    /**
     * Tarik SEMUA halaman. Run yang gagal di tengah tetap menyimpan halaman
     * yang sudah masuk, tapi penandaan `missing_at` dilewati (data belum lengkap).
     */
    public function pull(?User $by = null, ?TalentaEmployeeSyncRun $run = null): TalentaEmployeeSyncRun
    {
        $run ??= TalentaEmployeeSyncRun::create([
            'type' => TalentaEmployeeSyncRun::TYPE_PULL,
            'triggered_by' => $by?->id,
        ]);
        $run->update(['status' => 'running', 'started_at' => now()]);

        $fetched = 0;
        $changed = 0;

        try {
            $page = 1;
            do {
                $response = $this->talenta->listEmployees($page);

                if (! $response->successful() || $response->json('status') !== 'success') {
                    throw new RuntimeException(
                        "Talenta list employee halaman {$page} gagal (HTTP {$response->status()}): "
                        . mb_substr((string) ($response->json('message') ?? $response->body()), 0, 300)
                    );
                }

                $rows = $response->json('data.employees') ?? [];
                $changed += $this->storePage($rows, $run);
                $fetched += count($rows);

                $lastPage = (int) $response->json('data.pagination.last_page', 1);
                $total = (int) $response->json('data.pagination.total', $fetched);

                // Progres live untuk UI.
                $run->update(['total_fetched' => $fetched, 'count_changed' => $changed]);
                $page++;
            } while ($page <= $lastPage && $rows !== []);

            // Hanya tandai "hilang dari Talenta" bila tarikan benar-benar lengkap.
            if ($fetched >= $total) {
                $this->markMissing($run);
            } else {
                Log::warning('Talenta pull tak lengkap, missing_at dilewati', ['fetched' => $fetched, 'total' => $total]);
            }

            $run->update(['status' => 'success', 'finished_at' => now()]);
        } catch (Throwable $e) {
            Log::error('Talenta pull karyawan gagal', ['run_id' => $run->id, 'error' => $e->getMessage()]);
            $run->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
        }

        return $run->refresh();
    }

    /**
     * Upsert 1 halaman. Return jumlah baris baru/berubah (-> pending).
     *
     * @param  array<int, array>  $rows
     */
    private function storePage(array $rows, TalentaEmployeeSyncRun $run): int
    {
        $mapped = collect($rows)->map(fn (array $row) => $this->fromApi($row))->keyBy('talenta_user_id');
        $existing = TalentaEmployee::whereIn('talenta_user_id', $mapped->keys()->all())->get()->keyBy('talenta_user_id');

        $changed = 0;
        $now = now();

        foreach ($mapped as $userId => $attrs) {
            $row = $existing->get($userId);
            $base = ['last_pull_run_id' => $run->id, 'fetched_at' => $now, 'missing_at' => null];

            if ($row && $row->payload_hash === $attrs['payload_hash']) {
                $row->update($base);
                continue;
            }

            // Baru atau berubah: proses lagi di Tahap 2 (termasuk yang sebelumnya diabaikan).
            $attrs += $base + ['sync_state' => 'pending', 'sync_error' => null];
            $row ? $row->update($attrs) : TalentaEmployee::create($attrs);
            $changed++;
        }

        return $changed;
    }

    /** Baris yang tak ikut tarikan ini = tak ada lagi di Talenta. Karyawan lokal tak disentuh. */
    private function markMissing(TalentaEmployeeSyncRun $run): void
    {
        TalentaEmployee::whereNull('missing_at')
            ->where(fn ($q) => $q->whereNull('last_pull_run_id')->orWhere('last_pull_run_id', '!=', $run->id))
            ->update(['missing_at' => now()]);
    }

    /**
     * Petakan 1 item response GET /employee (v2) ke kolom talenta_employees.
     *
     * Hanya field yang dibutuhkan yang disimpan. PII sensitif (NIK, identitas,
     * alamat, tgl lahir, payroll, keluarga, pendidikan) SENGAJA dibuang.
     */
    public function fromApi(array $row): array
    {
        $personal = $row['personal'] ?? [];
        $employment = $row['employment'] ?? [];
        $str = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;

        $attrs = [
            'talenta_user_id' => (string) $row['user_id'],
            'barcode' => $str($personal['barcode'] ?? null),
            'employee_code' => $str($employment['employee_id'] ?? null),
            'full_name' => preg_replace('/\s+/', ' ', trim(($personal['first_name'] ?? '') . ' ' . ($personal['last_name'] ?? ''))),
            'email' => $str($personal['email'] ?? null),
            'job_position' => $str($employment['job_position'] ?? null),
            'organization_name' => $str($employment['organization_name'] ?? null),
            // "0" adalah cabang sungguhan (HO), jangan dianggap kosong.
            'branch_id' => $str($employment['branch_id'] ?? null),
            'branch_name' => $str($employment['branch'] ?? null),
            'status' => $str($employment['status'] ?? null),
            'resign_date' => $str($employment['resign_date'] ?? null),
        ];

        $extra = [
            'sso_id' => $row['sso_id'] ?? null,
            'employment_status' => $employment['employment_status'] ?? null,
            'job_level' => $employment['job_level'] ?? null,
            'join_date' => $str($employment['join_date'] ?? null),
            'end_date' => $str($employment['end_date'] ?? null),
        ];

        // updated_at Talenta tak ikut hash: ia berubah juga untuk field yang tak
        // kita simpan (payroll dll) dan hanya akan memicu proses ulang sia-sia.
        $attrs['payload_hash'] = hash('sha256', json_encode($attrs + $extra));
        $attrs['payload'] = $extra + ['talenta_updated_at' => $row['updated_at'] ?? null];

        return $attrs;
    }
}
