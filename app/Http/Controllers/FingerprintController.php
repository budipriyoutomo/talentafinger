<?php

namespace App\Http\Controllers;

use App\Jobs\DeleteFingerprintsBulk;
use App\Jobs\DistributeFingerprintsBulk;
use App\Jobs\SyncFingerprintsBulk;
use App\Models\BiometricTemplate;
use App\Models\Employee;
use App\Models\FingerprintDeleteJob;
use App\Models\FingerprintDistributeJob;
use App\Models\FingerprintSyncJob;
use App\Models\Machine;
use App\Services\DeviceCommandService;
use App\Services\FingerprintMasterService;
use App\Services\ZkSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Sinkronisasi sidik jari antar mesin (TCP 4370) dan master template di DB.
 *
 * Semua aksi di sini menulis ke PERANGKAT, jadi tiap mesin yang disebut —
 * sumber MAUPUN tujuan — diperiksa satu per satu lewat MachinePolicy. Tanpa itu,
 * seseorang bisa memakai mesin di outlet-nya sebagai sumber untuk menyuntik
 * sidik jari ke mesin outlet lain.
 */
class FingerprintController extends Controller
{
    public function __construct(
        private ZkSyncService $zk,
        private FingerprintMasterService $master,
        private DeviceCommandService $commands,
    ) {}

    // ===== Sync langsung antar mesin (mesin -> mesin) =====

    /** Tarik template 1 PIN dari mesin sumber, pasang ke mesin tujuan. */
    public function sync(Request $request)
    {
        $this->authorizePermission($request, 'fingerprint.sync');

        $data = $request->validate([
            'source_machine_id' => 'required|exists:machines,id',
            'pin' => 'required|string',
            'target_machine_ids' => 'required|array|min:1',
            'target_machine_ids.*' => 'required|exists:machines,id',
        ]);

        $source = Machine::findOrFail($data['source_machine_id']);
        $targets = Machine::whereIn('id', $data['target_machine_ids'])->get();

        $this->authorizeMachines($source, ...$targets);

        if (! $source->ip_address) {
            return response()->json(['ok' => false, 'error' => "Mesin sumber {$source->name} belum punya IP."], 422);
        }
        $noIp = $targets->whereNull('ip_address');
        if ($noIp->isNotEmpty()) {
            return response()->json(['ok' => false, 'error' => 'Mesin tujuan tanpa IP: ' . $noIp->pluck('name')->join(', ')], 422);
        }

        $result = $this->zk->syncPin($source, $targets->all(), $data['pin']);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /** Batch: sebar BANYAK PIN sekaligus dari 1 mesin sumber ke banyak mesin tujuan. */
    public function syncBulk(Request $request)
    {
        $this->authorizePermission($request, 'fingerprint.sync');

        $data = $request->validate([
            'source_machine_id' => 'required|exists:machines,id',
            'pins' => 'required|array|min:1',
            'pins.*' => 'required|string',
            'target_machine_ids' => 'required|array|min:1',
            'target_machine_ids.*' => 'required|exists:machines,id',
        ]);

        $source = Machine::findOrFail($data['source_machine_id']);
        // Buang mesin sumber dari daftar tujuan (tak perlu push balik ke dirinya).
        $targets = Machine::whereIn('id', $data['target_machine_ids'])
            ->where('id', '!=', $source->id)
            ->get();

        $this->authorizeMachines($source, ...$targets);

        if (! $source->ip_address) {
            return response()->json(['ok' => false, 'error' => "Mesin sumber {$source->name} belum punya IP."], 422);
        }
        if ($targets->isEmpty()) {
            return response()->json(['ok' => false, 'error' => 'Tidak ada mesin tujuan yang valid (selain mesin sumber).'], 422);
        }
        $noIp = $targets->whereNull('ip_address');
        if ($noIp->isNotEmpty()) {
            return response()->json(['ok' => false, 'error' => 'Mesin tujuan tanpa IP: ' . $noIp->pluck('name')->join(', ')], 422);
        }

        // Buang PIN duplikat, jaga urutan asli.
        $pins = array_values(array_unique(array_map('strval', $data['pins'])));

        // Buat baris status lalu kerjakan di background (queue). Frontend polling
        // /fingerprint/sync-jobs/{id} untuk progres & hasil.
        $job = FingerprintSyncJob::create([
            'source_machine_id' => $source->id,
            'target_machine_ids' => $targets->pluck('id')->all(),
            'pins' => $pins,
            'status' => 'queued',
            'progress_total' => count($pins),
            'progress_done' => 0,
        ]);

        SyncFingerprintsBulk::dispatch($job->id);

        return response()->json([
            'ok' => true,
            'job_id' => $job->id,
            'total' => count($pins),
            'message' => 'Sebar ' . count($pins) . ' karyawan diproses di background.',
        ], 202);
    }

    /** Status sebar massal (dipolling frontend selama job berjalan). */
    public function syncJob(Request $request, string $id)
    {
        $this->authorizePermission($request, 'fingerprint.view');

        // id harus UUID valid; selain itu Postgres lempar error (bukan sekadar
        // "tak ada baris"), jadi tolak lebih awal sebagai 404.
        if (! Str::isUuid($id)) {
            return response()->json(['ok' => false, 'error' => 'Job tidak ditemukan.'], 404);
        }

        $job = FingerprintSyncJob::find($id);
        if (! $job) {
            return response()->json(['ok' => false, 'error' => 'Job tidak ditemukan.'], 404);
        }

        $source = Machine::find($job->source_machine_id);
        if ($source) {
            $this->authorize('view', $source);
        }

        $targets = Machine::whereIn('id', $job->target_machine_ids)->pluck('name')->all();

        return response()->json([
            'ok' => true,
            'job_id' => $job->id,
            'status' => $job->status,
            'source' => $source?->name,
            'targets' => $targets,
            'progress_total' => $job->progress_total,
            'progress_done' => $job->progress_done,
            'summary' => $job->summary,
            'items' => $job->items ?? [],
            'error' => $job->error,
        ]);
    }

    // ===== Hapus massal user di mesin =====

    /** Hapus MASSAL user (beserta sidik jarinya) dari satu mesin via 4370, di background. */
    public function deleteBulk(Request $request)
    {
        $this->authorizePermission($request, 'fingerprint.delete');

        $data = $request->validate([
            'machine_id' => 'required|exists:machines,id',
            'pins' => 'required|array|min:1',
            'pins.*' => 'required|string',
        ]);

        $machine = Machine::findOrFail($data['machine_id']);
        $this->authorize('view', $machine);

        if (! $machine->ip_address) {
            return response()->json(['ok' => false, 'error' => "Mesin {$machine->name} belum punya IP."], 422);
        }

        // Buang PIN duplikat, jaga urutan.
        $pins = array_values(array_unique(array_map('strval', $data['pins'])));

        $job = FingerprintDeleteJob::create([
            'machine_id' => $machine->id,
            'pins' => $pins,
            'status' => 'queued',
            'progress_total' => count($pins),
            'progress_done' => 0,
        ]);

        DeleteFingerprintsBulk::dispatch($job->id);

        return response()->json([
            'ok' => true,
            'job_id' => $job->id,
            'total' => count($pins),
            'message' => 'Hapus ' . count($pins) . ' user diproses di background.',
        ], 202);
    }

    /** Status hapus massal (dipolling frontend). */
    public function deleteJob(Request $request, string $id)
    {
        $this->authorizePermission($request, 'fingerprint.view');

        if (! Str::isUuid($id)) {
            return response()->json(['ok' => false, 'error' => 'Job tidak ditemukan.'], 404);
        }

        $job = FingerprintDeleteJob::find($id);
        if (! $job) {
            return response()->json(['ok' => false, 'error' => 'Job tidak ditemukan.'], 404);
        }

        if ($job->machine) {
            $this->authorize('view', $job->machine);
        }

        return response()->json([
            'ok' => true,
            'job_id' => $job->id,
            'status' => $job->status,
            'machine' => $job->machine?->name,
            'progress_total' => $job->progress_total,
            'progress_done' => $job->progress_done,
            'summary' => $job->summary,
            'items' => $job->items ?? [],
            'error' => $job->error,
        ]);
    }

    // ===== Master template di DB (sumber kebenaran) =====

    /** Push sidik jari satu PIN (dari DB) ke beberapa mesin tujuan sekaligus. */
    public function pushTemplate(Request $request)
    {
        $this->authorizePermission($request, 'fingerprint.sync');

        $data = $request->validate([
            'biometric_id' => 'required|string|exists:biometric_templates,biometric_id',
            'machine_ids' => 'required|array|min:1',
            'machine_ids.*' => 'required|exists:machines,id',
        ]);

        $machines = Machine::whereIn('id', $data['machine_ids'])->get();
        $this->authorizeMachines(...$machines);

        $results = [];
        $totalCommands = 0;

        foreach ($machines as $machine) {
            $queued = $this->commands->queuePushFingerprint($machine, $data['biometric_id']);
            $totalCommands += $queued;
            $results[] = ['machine' => $machine->name, 'commands' => $queued];
        }

        return response()->json([
            'success' => true,
            'message' => "Sidik jari PIN {$data['biometric_id']} diantrekan ke " . $machines->count() . " mesin ({$totalCommands} perintah). Mesin akan memasangnya saat polling berikutnya (±30 detik).",
            'results' => $results,
        ]);
    }

    public function deleteTemplate(Request $request, string $biometricId)
    {
        $this->authorizePermission($request, 'fingerprint.delete');

        // Template di DB dikunci ke karyawan; ikuti scope karyawan tsb bila ada.
        $employee = Employee::where('biometric_id', $biometricId)->first();
        if ($employee) {
            $this->authorize('update', $employee);
        } else {
            // Template yatim (tak terhubung karyawan) hanya boleh disentuh admin.
            $this->assertOutletInScope($request, null);
        }

        $deleted = BiometricTemplate::where('biometric_id', $biometricId)->delete();

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    /** TARIK template dari mesin sumber -> simpan ke DB (employee_id). */
    public function capture(Request $request, string $id)
    {
        $employee = Employee::findOrFail($id);
        $this->authorize('syncFingerprint', $employee);

        $data = $request->validate([
            'source_machine_id' => 'required|exists:machines,id',
        ]);

        $source = Machine::findOrFail($data['source_machine_id']);
        $this->authorize('view', $source);

        $result = $this->master->captureFromMachine($employee, $source);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /** SEBAR template karyawan dari DB -> banyak mesin tujuan via TCP 4370. */
    public function distribute(Request $request, string $id)
    {
        $employee = Employee::findOrFail($id);
        $this->authorize('syncFingerprint', $employee);

        $data = $request->validate([
            'target_machine_ids' => 'required|array|min:1',
            'target_machine_ids.*' => 'required|exists:machines,id',
        ]);

        $targets = Machine::whereIn('id', $data['target_machine_ids'])->get();
        $this->authorizeMachines(...$targets);

        if ($employee->biometricTemplates()->count() === 0) {
            return response()->json(['ok' => false, 'error' => 'Karyawan belum punya template di DB. Tarik dulu dari mesin.'], 422);
        }

        $results = $targets->map(fn ($m) => $this->master->distributeToMachine($employee, $m))->all();
        $okAll = collect($results)->every(fn ($r) => $r['ok'] ?? false);

        return response()->json([
            'ok' => $okAll,
            'employee' => $employee->name,
            'results' => $results,
        ], $okAll ? 200 : 422);
    }

    /** Hapus template karyawan dari DB (tidak menyentuh mesin). */
    public function destroyEmployeeTemplates(Request $request, string $id)
    {
        $employee = Employee::findOrFail($id);
        $this->authorizePermission($request, 'fingerprint.delete');
        $this->authorize('update', $employee);

        $deleted = $employee->biometricTemplates()->delete();

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    /** SEBAR MASSAL dari DB: banyak karyawan -> banyak mesin, di background. */
    public function distributeBulk(Request $request)
    {
        $this->authorizePermission($request, 'fingerprint.sync');

        $data = $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'required|exists:employees,id',
            'target_machine_ids' => 'required|array|min:1',
            'target_machine_ids.*' => 'required|exists:machines,id',
        ]);

        $employeeIds = array_values(array_unique($data['employee_ids']));
        $targets = Machine::whereIn('id', $data['target_machine_ids'])->get();

        $this->authorizeMachines(...$targets);
        foreach (Employee::whereIn('id', $employeeIds)->get() as $employee) {
            $this->authorize('syncFingerprint', $employee);
        }

        $noIp = $targets->whereNull('ip_address');
        if ($noIp->isNotEmpty()) {
            return response()->json(['ok' => false, 'error' => 'Mesin tujuan tanpa IP: ' . $noIp->pluck('name')->join(', ')], 422);
        }

        $job = FingerprintDistributeJob::create([
            'employee_ids' => $employeeIds,
            'target_machine_ids' => $targets->pluck('id')->all(),
            'status' => 'queued',
            'progress_total' => count($employeeIds),
            'progress_done' => 0,
        ]);

        DistributeFingerprintsBulk::dispatch($job->id);

        return response()->json([
            'ok' => true,
            'job_id' => $job->id,
            'total' => count($employeeIds),
            'message' => 'Sebar ' . count($employeeIds) . ' karyawan dari DB diproses di background.',
        ], 202);
    }

    /** Status sebar massal dari DB (dipolling frontend). */
    public function distributeJob(Request $request, string $id)
    {
        $this->authorizePermission($request, 'fingerprint.view');

        if (! Str::isUuid($id)) {
            return response()->json(['ok' => false, 'error' => 'Job tidak ditemukan.'], 404);
        }

        $job = FingerprintDistributeJob::find($id);
        if (! $job) {
            return response()->json(['ok' => false, 'error' => 'Job tidak ditemukan.'], 404);
        }

        $machines = Machine::whereIn('id', $job->target_machine_ids)->get();
        $this->authorizeMachines(...$machines);

        return response()->json([
            'ok' => true,
            'job_id' => $job->id,
            'status' => $job->status,
            'targets' => $machines->pluck('name')->all(),
            'progress_total' => $job->progress_total,
            'progress_done' => $job->progress_done,
            'summary' => $job->summary,
            'items' => $job->items ?? [],
            'error' => $job->error,
        ]);
    }

    /**
     * Tiap mesin yang terlibat harus berada dalam wewenang user — termasuk mesin
     * TUJUAN, bukan cuma sumber. Menulis sidik jari ke mesin outlet lain sama
     * saja memberi orang akses fisik ke sana.
     */
    private function authorizeMachines(Machine ...$machines): void
    {
        foreach ($machines as $machine) {
            $this->authorize('view', $machine);
        }
    }
}
