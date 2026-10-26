<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\TalentaBranchOutlet;
use App\Models\TalentaEmployee;
use App\Models\TalentaEmployeeSyncRun;
use App\Services\TalentaEmployeeSyncRunner;
use App\Services\TalentaEmployeeSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tab "Sinkron Talenta" di halaman Employees (TODO-Employee-Sync-Talenta.md §7).
 *
 * Sync mengubah karyawan di SEMUA outlet, jadi selain permission
 * employee.talenta_sync user juga wajib bebas scope (admin / data.all).
 */
class TalentaEmployeeSyncController extends Controller
{
    /** Kartu ringkasan + status run terakhir (dipolling saat run berjalan). */
    public function summary(Request $request, TalentaEmployeeSyncRunner $runner)
    {
        $this->authorizeSync($request);

        $countBy = fn (string $column) => TalentaEmployee::query()
            ->select($column, DB::raw('count(*) as n'))
            ->groupBy($column)
            ->pluck('n', $column)
            ->map(fn ($n) => (int) $n);

        $lastRun = fn (string $type) => TalentaEmployeeSyncRun::with('triggeredBy:id,name')
            ->where('type', $type)->latest()->first();

        return response()->json([
            'total' => TalentaEmployee::count(),
            'sync_state' => $countBy('sync_state'),
            'match_status' => $countBy('match_status'),
            'missing' => TalentaEmployee::whereNotNull('missing_at')->count(),
            'without_barcode' => TalentaEmployee::whereNull('barcode')->whereNull('missing_at')->count(),
            // D10: karyawan lokal yang tak tertaut ke data Talenta mana pun.
            'local_not_in_talenta' => Employee::whereNull('talenta_user_id')->count(),
            'linked_without_outlet' => Employee::whereNotNull('talenta_user_id')->whereDoesntHave('outlets')->count(),
            'last_pull' => $this->runPayload($lastRun(TalentaEmployeeSyncRun::TYPE_PULL)),
            'last_sync' => $this->runPayload($lastRun(TalentaEmployeeSyncRun::TYPE_SYNC)),
            'running' => $runner->activeRun() !== null,
            'auto_sync' => [
                'enabled' => (bool) Setting::value('talenta.employee_sync'),
                'interval' => (int) Setting::value('talenta.employee_sync_interval', 1440),
            ],
        ]);
    }

    /** Isi tabel sementara + karyawan lokal yang tertaut, untuk perbandingan. */
    public function index(Request $request)
    {
        $this->authorizeSync($request);

        $rows = TalentaEmployee::with(['employee' => fn ($q) => $q->withCount('outlets')])
            ->orderBy('full_name')
            ->get();

        return response()->json($rows->map(fn (TalentaEmployee $t) => $this->rowPayload($t))->values());
    }

    public function runs(Request $request)
    {
        $this->authorizeSync($request);

        return response()->json(
            TalentaEmployeeSyncRun::with('triggeredBy:id,name')->latest()->limit(30)->get()
                ->map(fn ($r) => $this->runPayload($r))
        );
    }

    /** Mulai pull / sync / keduanya di background. */
    public function start(Request $request, TalentaEmployeeSyncRunner $runner)
    {
        $this->authorizeSync($request);

        $data = $request->validate(['mode' => ['required', Rule::in(TalentaEmployeeSyncRunner::MODES)]]);

        try {
            $runs = $runner->dispatch($data['mode'], $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 409);
        }

        AuditLog::record('talenta_employee.sync_started', null, ['mode' => $data['mode']]);

        return response()->json([
            'ok' => true,
            'runs' => array_map(fn ($r) => $this->runPayload($r), $runs),
        ], 202);
    }

    // ===== Penyelesaian konflik =====

    public function link(Request $request, string $id, TalentaEmployeeSyncService $sync)
    {
        $this->authorizeSync($request);

        $data = $request->validate([
            'employee_id' => 'required|uuid|exists:employees,id',
            'overwrite_badge' => 'boolean',
        ]);

        return $this->resolve(fn () => $sync->resolveLink(
            TalentaEmployee::findOrFail($id),
            Employee::findOrFail($data['employee_id']),
            (bool) ($data['overwrite_badge'] ?? false),
            $request->user(),
        ), $id);
    }

    public function createEmployee(Request $request, string $id, TalentaEmployeeSyncService $sync)
    {
        $this->authorizeSync($request);

        return $this->resolve(fn () => $sync->resolveCreateNew(TalentaEmployee::findOrFail($id), $request->user()), $id);
    }

    public function ignore(Request $request, string $id, TalentaEmployeeSyncService $sync)
    {
        $this->authorizeSync($request);

        return $this->resolve(fn () => $sync->ignore(TalentaEmployee::findOrFail($id), $request->user()), $id);
    }

    // ===== Mapping cabang Talenta -> outlet (D11) =====

    public function branches(Request $request)
    {
        $this->authorizeSync($request);

        $mapping = TalentaBranchOutlet::pluck('outlet_id', 'talenta_branch_id');

        $branches = TalentaEmployee::query()
            ->whereNotNull('branch_id')
            ->whereNull('missing_at')
            ->select('branch_id', DB::raw('max(branch_name) as branch_name'), DB::raw('count(*) as employees'))
            ->groupBy('branch_id')
            ->orderBy('branch_name')
            ->get()
            ->map(fn ($b) => [
                'branch_id' => $b->branch_id,
                'branch_name' => $b->branch_name,
                'employees' => (int) $b->employees,
                'outlet_id' => $mapping[$b->branch_id] ?? null,
            ]);

        return response()->json([
            'branches' => $branches,
            'outlets' => Outlet::with('brand:id,name')->orderBy('name')->get(['id', 'name', 'brand_id'])
                ->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'brand_name' => $o->brand?->name]),
        ]);
    }

    /** Simpan mapping; apply=true langsung isi outlet karyawan tertaut yang belum punya outlet. */
    public function saveBranches(Request $request, TalentaEmployeeSyncService $sync)
    {
        $this->authorizeSync($request);

        $data = $request->validate([
            'mappings' => 'present|array',
            'mappings.*.branch_id' => 'required|string|max:50',
            'mappings.*.branch_name' => 'nullable|string|max:150',
            'mappings.*.outlet_id' => 'nullable|uuid|exists:outlets,id',
            'apply' => 'boolean',
        ]);

        $before = TalentaBranchOutlet::pluck('outlet_id', 'talenta_branch_id')->all();

        DB::transaction(function () use ($data) {
            foreach ($data['mappings'] as $m) {
                if (empty($m['outlet_id'])) {
                    TalentaBranchOutlet::where('talenta_branch_id', $m['branch_id'])->delete();
                    continue;
                }

                TalentaBranchOutlet::updateOrCreate(
                    ['talenta_branch_id' => $m['branch_id']],
                    ['talenta_branch_name' => $m['branch_name'] ?? $m['branch_id'], 'outlet_id' => $m['outlet_id']],
                );
            }
        });

        $after = TalentaBranchOutlet::pluck('outlet_id', 'talenta_branch_id')->all();
        AuditLog::record('talenta_employee.branch_mapping_updated', null, AuditLog::diff($before, $after + array_fill_keys(array_keys($before), null)));

        $filled = ($data['apply'] ?? false) ? $sync->applyBranchOutlets($request->user()) : 0;

        return response()->json(['ok' => true, 'filled' => $filled]);
    }

    // ===== Internal =====

    private function authorizeSync(Request $request): void
    {
        $this->authorizePermission($request, 'employee.talenta_sync');

        if (! $request->user()->hasFullDataAccess()) {
            throw new AccessDeniedHttpException('Sinkron Talenta mengubah karyawan di semua outlet: butuh akses data semua outlet.');
        }
    }

    private function resolve(callable $action, string $id)
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        $row = TalentaEmployee::with(['employee' => fn ($q) => $q->withCount('outlets')])->findOrFail($id);

        return response()->json(['ok' => true, 'row' => $this->rowPayload($row)]);
    }

    private function rowPayload(TalentaEmployee $t): array
    {
        $e = $t->employee;

        return [
            'id' => $t->id,
            'talenta_user_id' => $t->talenta_user_id,
            'barcode' => $t->barcode,
            'employee_code' => $t->employee_code,
            'full_name' => $t->full_name,
            'email' => $t->email,
            'job_position' => $t->job_position,
            'organization_name' => $t->organization_name,
            'branch_name' => $t->branch_name,
            'status' => $t->status,
            'resign_date' => $t->resign_date?->toDateString(),
            'match_status' => $t->match_status,
            'sync_state' => $t->sync_state,
            'sync_error' => $t->sync_error,
            'synced_at' => $t->synced_at?->toIso8601String(),
            'missing' => $t->missing_at !== null,
            'employee' => $e ? [
                'id' => $e->id,
                'name' => $e->name,
                'talenta_employee_id' => $e->talenta_employee_id,
                'employee_code' => $e->employee_code,
                'biometric_id' => $e->biometric_id,
                'is_active' => $e->is_active,
                'linked' => $e->talenta_user_id === $t->talenta_user_id,
                'outlets_count' => $e->outlets_count ?? null,
            ] : null,
        ];
    }

    private function runPayload(?TalentaEmployeeSyncRun $r): ?array
    {
        if (! $r) {
            return null;
        }

        return [
            'id' => $r->id,
            'type' => $r->type,
            'status' => $r->status,
            'triggered_by' => $r->triggeredBy?->name ?? ($r->triggered_by ? null : 'Scheduler'),
            'total_fetched' => $r->total_fetched,
            'count_changed' => $r->count_changed,
            'count_created' => $r->count_created,
            'count_updated' => $r->count_updated,
            'count_deactivated' => $r->count_deactivated,
            'count_conflict' => $r->count_conflict,
            'count_error' => $r->count_error,
            'error_message' => $r->error_message,
            'created_at' => $r->created_at?->toIso8601String(),
            'started_at' => $r->started_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
        ];
    }
}
