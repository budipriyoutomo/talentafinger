<?php

use Illuminate\Support\Facades\Route;
use App\Models\Machine;
use App\Models\Employee;
use App\Models\Company;
use App\Models\Brand;
use App\Models\Outlet;
use App\Models\AttendanceLog;
use App\Models\Setting;
use App\Http\Controllers\AttendanceController;
use Illuminate\Validation\Rule;

// API endpoints for dashboard
Route::get('/machines', function () {
    // Kolom `status` di DB hanya di-set 'online' saat mesin push data dan tidak
    // pernah direset, jadi nilainya basi. Hitung status LIVE dari last_seen_at
    // (window 5 menit via isOnline()) supaya heartbeat monitor akurat.
    return Machine::all()->each(function ($m) {
        $m->status = $m->isOnline() ? 'online' : 'offline';
    });
});

Route::post('/machines', function (Illuminate\Http\Request $request) {
    return Machine::create($request->validate([
        'serial_number' => 'required|unique:machines',
        'name' => 'required',
        'location' => 'nullable',
        'ip_address' => 'nullable|ip',
        'sdk_port' => 'nullable|integer|min:1|max:65535',
        'is_active' => 'boolean',
    ]));
});

Route::put('/machines/{id}', function (Illuminate\Http\Request $request, $id) {
    $machine = Machine::findOrFail($id);

    $data = $request->validate([
        'serial_number' => 'required|unique:machines,serial_number,' . $id,
        'name' => 'required',
        'location' => 'nullable',
        'ip_address' => 'nullable|ip',
        'sdk_port' => 'nullable|integer|min:1|max:65535',
        'is_active' => 'boolean',
    ]);

    $machine->update($data);

    return $machine;
});

Route::delete('/machines/{id}', function ($id) {
    Machine::findOrFail($id)->delete();
    return response()->json(['success' => true]);
});

// Antrekan perintah sync time ke mesin (dieksekusi saat mesin polling getrequest).
Route::post('/machines/{id}/sync-time', function ($id) {
    $machine = Machine::findOrFail($id);
    $cmd = app(App\Services\DeviceCommandService::class)->queueSyncTime($machine);

    return response()->json([
        'success' => true,
        'message' => 'Perintah sync time diantrekan. Mesin akan menyesuaikan jam saat polling berikutnya (±30 detik).',
        'command' => $cmd->command,
    ]);
});

// ===== Sync sidik jari via TCP 4370 (pyzk) =====
// Tarik template 1 PIN dari mesin sumber, pasang ke mesin tujuan.
Route::post('/fingerprint/sync', function (Illuminate\Http\Request $request) {
    $data = $request->validate([
        'source_machine_id' => 'required|exists:machines,id',
        'pin' => 'required|string',
        'target_machine_ids' => 'required|array|min:1',
        'target_machine_ids.*' => 'required|exists:machines,id',
    ]);

    $source = Machine::findOrFail($data['source_machine_id']);
    $targets = Machine::whereIn('id', $data['target_machine_ids'])->get();

    if (! $source->ip_address) {
        return response()->json(['ok' => false, 'error' => "Mesin sumber {$source->name} belum punya IP."], 422);
    }
    $noIp = $targets->whereNull('ip_address');
    if ($noIp->isNotEmpty()) {
        return response()->json(['ok' => false, 'error' => 'Mesin tujuan tanpa IP: ' . $noIp->pluck('name')->join(', ')], 422);
    }

    $result = app(App\Services\ZkSyncService::class)
        ->syncPin($source, $targets->all(), $data['pin']);

    return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
});

// Batch: sebar BANYAK PIN sekaligus dari 1 mesin sumber ke banyak mesin tujuan.
Route::post('/fingerprint/sync-bulk', function (Illuminate\Http\Request $request) {
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
    $job = App\Models\FingerprintSyncJob::create([
        'source_machine_id' => $source->id,
        'target_machine_ids' => $targets->pluck('id')->all(),
        'pins' => $pins,
        'status' => 'queued',
        'progress_total' => count($pins),
        'progress_done' => 0,
    ]);

    App\Jobs\SyncFingerprintsBulk::dispatch($job->id);

    return response()->json([
        'ok' => true,
        'job_id' => $job->id,
        'total' => count($pins),
        'message' => 'Sebar ' . count($pins) . ' karyawan diproses di background.',
    ], 202);
});

// Status sebar massal (dipolling frontend selama job berjalan).
Route::get('/fingerprint/sync-jobs/{id}', function ($id) {
    // id harus UUID valid; selain itu Postgres lempar error (bukan sekadar
    // "tak ada baris"), jadi tolak lebih awal sebagai 404.
    if (! Illuminate\Support\Str::isUuid($id)) {
        return response()->json(['ok' => false, 'error' => 'Job tidak ditemukan.'], 404);
    }

    $job = App\Models\FingerprintSyncJob::find($id);
    if (! $job) {
        return response()->json(['ok' => false, 'error' => 'Job tidak ditemukan.'], 404);
    }

    $source = Machine::find($job->source_machine_id);
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
});

// Diagnostik: info & daftar user di mesin (via 4370).
Route::get('/machines/{id}/zk-info', function ($id) {
    $m = Machine::findOrFail($id);
    return response()->json(app(App\Services\ZkSyncService::class)->info($m));
});
Route::get('/machines/{id}/zk-users', function ($id) {
    $m = Machine::findOrFail($id);
    return response()->json(app(App\Services\ZkSyncService::class)->listUsers($m));
});

// Hapus 1 user (beserta sidik jarinya) dari mesin via 4370. Permanen di perangkat;
// tak menyentuh mapping/log di DB. Hapus per jari tidak didukung protokol ZK.
Route::delete('/machines/{id}/zk-users/{pin}', function ($id, $pin) {
    $m = Machine::findOrFail($id);
    if (! $m->ip_address) {
        return response()->json(['ok' => false, 'error' => "Mesin {$m->name} belum punya IP."], 422);
    }
    $result = app(App\Services\ZkSyncService::class)->delete($m, (string) $pin);
    return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
});

// Hapus SEMUA log presensi (records) di mesin via 4370. Permanen di perangkat;
// tidak menyentuh log di DB / Talenta. ZK tak mendukung hapus parsial by tanggal.
Route::post('/machines/{id}/clear-attendance', function ($id) {
    $m = Machine::findOrFail($id);
    if (! $m->ip_address) {
        return response()->json(['ok' => false, 'error' => "Mesin {$m->name} belum punya IP."], 422);
    }
    $result = app(App\Services\ZkSyncService::class)->clearAttendance($m);
    return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
});

// Hapus MASSAL user (beserta sidik jarinya) dari satu mesin via 4370, di background.
Route::post('/fingerprint/delete-bulk', function (Illuminate\Http\Request $request) {
    $data = $request->validate([
        'machine_id' => 'required|exists:machines,id',
        'pins' => 'required|array|min:1',
        'pins.*' => 'required|string',
    ]);

    $machine = Machine::findOrFail($data['machine_id']);
    if (! $machine->ip_address) {
        return response()->json(['ok' => false, 'error' => "Mesin {$machine->name} belum punya IP."], 422);
    }

    // Buang PIN duplikat, jaga urutan.
    $pins = array_values(array_unique(array_map('strval', $data['pins'])));

    $job = App\Models\FingerprintDeleteJob::create([
        'machine_id' => $machine->id,
        'pins' => $pins,
        'status' => 'queued',
        'progress_total' => count($pins),
        'progress_done' => 0,
    ]);

    App\Jobs\DeleteFingerprintsBulk::dispatch($job->id);

    return response()->json([
        'ok' => true,
        'job_id' => $job->id,
        'total' => count($pins),
        'message' => 'Hapus ' . count($pins) . ' user diproses di background.',
    ], 202);
});

// Status hapus massal (dipolling frontend).
Route::get('/fingerprint/delete-jobs/{id}', function ($id) {
    if (! Illuminate\Support\Str::isUuid($id)) {
        return response()->json(['ok' => false, 'error' => 'Job tidak ditemukan.'], 404);
    }

    $job = App\Models\FingerprintDeleteJob::find($id);
    if (! $job) {
        return response()->json(['ok' => false, 'error' => 'Job tidak ditemukan.'], 404);
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
});

// ===== Biometric templates (sidik jari) =====
// Push sidik jari satu PIN ke beberapa mesin tujuan sekaligus.
Route::post('/biometric-templates/push', function (Illuminate\Http\Request $request) {
    $data = $request->validate([
        'biometric_id' => 'required|string|exists:biometric_templates,biometric_id',
        'machine_ids' => 'required|array|min:1',
        'machine_ids.*' => 'required|exists:machines,id',
    ]);

    $service = app(App\Services\DeviceCommandService::class);
    $results = [];
    $totalCommands = 0;

    foreach ($data['machine_ids'] as $machineId) {
        $machine = Machine::find($machineId);
        $queued = $service->queuePushFingerprint($machine, $data['biometric_id']);
        $totalCommands += $queued;
        $results[] = ['machine' => $machine->name, 'commands' => $queued];
    }

    return response()->json([
        'success' => true,
        'message' => "Sidik jari PIN {$data['biometric_id']} diantrekan ke " . count($data['machine_ids']) . " mesin ({$totalCommands} perintah). Mesin akan memasangnya saat polling berikutnya (±30 detik).",
        'results' => $results,
    ]);
});

Route::delete('/biometric-templates/{biometricId}', function ($biometricId) {
    $deleted = App\Models\BiometricTemplate::where('biometric_id', $biometricId)->delete();
    return response()->json(['success' => true, 'deleted' => $deleted]);
});

// ===== Master organisasi: Company -> Brand -> Outlet =====
// Hierarki: 1 company > banyak brand > banyak outlet. Karyawan ditautkan ke outlet.

// --- Companies ---
Route::get('/companies', function () {
    // Sertakan brand & outlet bertingkat untuk dropdown cascading di frontend.
    return Company::with('brands.outlets')->orderBy('name')->get();
});

Route::post('/companies', function (Illuminate\Http\Request $request) {
    return Company::create($request->validate([
        'name' => 'required|unique:companies,name',
        'is_active' => 'boolean',
    ]));
});

Route::put('/companies/{id}', function (Illuminate\Http\Request $request, $id) {
    $company = Company::findOrFail($id);
    $company->update($request->validate([
        'name' => ['required', Rule::unique('companies', 'name')->ignore($id)],
        'is_active' => 'boolean',
    ]));
    return $company;
});

Route::delete('/companies/{id}', function ($id) {
    // cascadeOnDelete: brand & outlet di bawahnya ikut terhapus; outlet_id
    // karyawan di-null-kan (nullOnDelete pada employees).
    Company::findOrFail($id)->delete();
    return response()->json(['success' => true]);
});

// --- Brands ---
Route::post('/brands', function (Illuminate\Http\Request $request) {
    return Brand::create($request->validate([
        'company_id' => 'required|exists:companies,id',
        'name' => [
            'required',
            Rule::unique('brands', 'name')->where(fn($q) => $q->where('company_id', $request->company_id)),
        ],
        'is_active' => 'boolean',
    ], [
        'name.unique' => 'Brand dengan nama ini sudah ada di company tersebut.',
    ]));
});

Route::put('/brands/{id}', function (Illuminate\Http\Request $request, $id) {
    $brand = Brand::findOrFail($id);
    $brand->update($request->validate([
        'company_id' => 'required|exists:companies,id',
        'name' => [
            'required',
            Rule::unique('brands', 'name')
                ->where(fn($q) => $q->where('company_id', $request->company_id))
                ->ignore($id),
        ],
        'is_active' => 'boolean',
    ]));
    return $brand;
});

Route::delete('/brands/{id}', function ($id) {
    Brand::findOrFail($id)->delete();
    return response()->json(['success' => true]);
});

// --- Outlets ---
Route::post('/outlets', function (Illuminate\Http\Request $request) {
    return Outlet::create($request->validate([
        'brand_id' => 'required|exists:brands,id',
        'name' => [
            'required',
            Rule::unique('outlets', 'name')->where(fn($q) => $q->where('brand_id', $request->brand_id)),
        ],
        'is_active' => 'boolean',
    ], [
        'name.unique' => 'Outlet dengan nama ini sudah ada di brand tersebut.',
    ]));
});

Route::put('/outlets/{id}', function (Illuminate\Http\Request $request, $id) {
    $outlet = Outlet::findOrFail($id);
    $outlet->update($request->validate([
        'brand_id' => 'required|exists:brands,id',
        'name' => [
            'required',
            Rule::unique('outlets', 'name')
                ->where(fn($q) => $q->where('brand_id', $request->brand_id))
                ->ignore($id),
        ],
        'is_active' => 'boolean',
    ]));
    return $outlet;
});

Route::delete('/outlets/{id}', function ($id) {
    Outlet::findOrFail($id)->delete();
    return response()->json(['success' => true]);
});

// ===== Employees (master karyawan) =====
Route::get('/employees', function () {
    return Employee::orderBy('name')->get();
});

Route::post('/employees', function (Illuminate\Http\Request $request) {
    return Employee::create($request->validate([
        'name' => 'required',
        'talenta_employee_id' => 'required|unique:employees,talenta_employee_id',
        'employee_code' => 'nullable',
        'biometric_id' => 'nullable|string|max:50',
        'outlet_id' => 'nullable|exists:outlets,id',
        'device_privilege' => 'nullable|integer|min:0|max:14',
        'is_active' => 'boolean',
    ]));
});

Route::put('/employees/{id}', function (Illuminate\Http\Request $request, $id) {
    $employee = Employee::findOrFail($id);

    $employee->update($request->validate([
        'name' => 'required',
        'talenta_employee_id' => ['required', Rule::unique('employees', 'talenta_employee_id')->ignore($id)],
        'employee_code' => 'nullable',
        'biometric_id' => 'nullable|string|max:50',
        'outlet_id' => 'nullable|exists:outlets,id',
        'device_privilege' => 'nullable|integer|min:0|max:14',
        'is_active' => 'boolean',
    ]));

    return $employee;
});

Route::delete('/employees/{id}', function ($id) {
    Employee::findOrFail($id)->delete();
    return response()->json(['success' => true]);
});

// Impor user dari mesin (halaman /fingerprints) -> master karyawan.
// Kunci dedup: biometric_id (PIN). Opsional langsung tarik template ke DB.
Route::post('/employees/import-from-machine', function (Illuminate\Http\Request $request) {
    $data = $request->validate([
        'biometric_id' => 'required|string|max:50',
        'name' => 'nullable|string|max:150',
        'source_machine_id' => 'required|exists:machines,id',
        'capture' => 'boolean',
    ]);

    $employee = Employee::where('biometric_id', $data['biometric_id'])->first();
    $created = false;

    if (! $employee) {
        $employee = Employee::create([
            'name' => $data['name'] ?: ('User ' . $data['biometric_id']),
            'biometric_id' => $data['biometric_id'],
            'is_active' => true,
        ]);
        $created = true;
    } elseif (! empty($data['name']) && $employee->name !== $data['name']) {
        // Selaraskan nama dari mesin bila berubah.
        $employee->update(['name' => $data['name']]);
    }

    // Opsional: sekalian tarik sidik jari ke DB (sumber kebenaran).
    $capture = null;
    if ($request->boolean('capture')) {
        $source = Machine::findOrFail($data['source_machine_id']);
        $capture = app(App\Services\FingerprintMasterService::class)
            ->captureFromMachine($employee, $source);
    }

    return response()->json([
        'ok' => true,
        'created' => $created,
        'employee' => [
            'id' => $employee->id,
            'name' => $employee->name,
            'biometric_id' => $employee->biometric_id,
        ],
        'capture' => $capture,
    ]);
});

// ===== Master sidik jari per karyawan (DB sebagai sumber kebenaran) =====
// TARIK template dari mesin sumber -> simpan ke DB (employee_id).
Route::post('/employees/{id}/fingerprints/capture', function (Illuminate\Http\Request $request, $id) {
    $employee = Employee::findOrFail($id);
    $data = $request->validate([
        'source_machine_id' => 'required|exists:machines,id',
    ]);
    $source = Machine::findOrFail($data['source_machine_id']);

    $result = app(App\Services\FingerprintMasterService::class)
        ->captureFromMachine($employee, $source);

    return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
});

// SEBAR template karyawan dari DB -> banyak mesin tujuan via TCP 4370.
Route::post('/employees/{id}/fingerprints/distribute', function (Illuminate\Http\Request $request, $id) {
    $employee = Employee::findOrFail($id);
    $data = $request->validate([
        'target_machine_ids' => 'required|array|min:1',
        'target_machine_ids.*' => 'required|exists:machines,id',
    ]);

    if ($employee->biometricTemplates()->count() === 0) {
        return response()->json(['ok' => false, 'error' => 'Karyawan belum punya template di DB. Tarik dulu dari mesin.'], 422);
    }

    $svc = app(App\Services\FingerprintMasterService::class);
    $targets = Machine::whereIn('id', $data['target_machine_ids'])->get();

    $results = $targets->map(fn ($m) => $svc->distributeToMachine($employee, $m))->all();
    $okAll = collect($results)->every(fn ($r) => $r['ok'] ?? false);

    return response()->json([
        'ok' => $okAll,
        'employee' => $employee->name,
        'results' => $results,
    ], $okAll ? 200 : 422);
});

// Hapus template karyawan dari DB (tidak menyentuh mesin).
Route::delete('/employees/{id}/fingerprints', function ($id) {
    $employee = Employee::findOrFail($id);
    $deleted = $employee->biometricTemplates()->delete();
    return response()->json(['success' => true, 'deleted' => $deleted]);
});

// SEBAR MASSAL dari DB: banyak karyawan -> banyak mesin, di background.
Route::post('/fingerprint/distribute-bulk', function (Illuminate\Http\Request $request) {
    $data = $request->validate([
        'employee_ids' => 'required|array|min:1',
        'employee_ids.*' => 'required|exists:employees,id',
        'target_machine_ids' => 'required|array|min:1',
        'target_machine_ids.*' => 'required|exists:machines,id',
    ]);

    $employeeIds = array_values(array_unique($data['employee_ids']));
    $targets = Machine::whereIn('id', $data['target_machine_ids'])->get();

    $noIp = $targets->whereNull('ip_address');
    if ($noIp->isNotEmpty()) {
        return response()->json(['ok' => false, 'error' => 'Mesin tujuan tanpa IP: ' . $noIp->pluck('name')->join(', ')], 422);
    }

    $job = App\Models\FingerprintDistributeJob::create([
        'employee_ids' => $employeeIds,
        'target_machine_ids' => $targets->pluck('id')->all(),
        'status' => 'queued',
        'progress_total' => count($employeeIds),
        'progress_done' => 0,
    ]);

    App\Jobs\DistributeFingerprintsBulk::dispatch($job->id);

    return response()->json([
        'ok' => true,
        'job_id' => $job->id,
        'total' => count($employeeIds),
        'message' => 'Sebar ' . count($employeeIds) . ' karyawan dari DB diproses di background.',
    ], 202);
});

// Status sebar massal dari DB (dipolling frontend).
Route::get('/fingerprint/distribute-jobs/{id}', function ($id) {
    if (! Illuminate\Support\Str::isUuid($id)) {
        return response()->json(['ok' => false, 'error' => 'Job tidak ditemukan.'], 404);
    }

    $job = App\Models\FingerprintDistributeJob::find($id);
    if (! $job) {
        return response()->json(['ok' => false, 'error' => 'Job tidak ditemukan.'], 404);
    }

    $targets = Machine::whereIn('id', $job->target_machine_ids)->pluck('name')->all();

    return response()->json([
        'ok' => true,
        'job_id' => $job->id,
        'status' => $job->status,
        'targets' => $targets,
        'progress_total' => $job->progress_total,
        'progress_done' => $job->progress_done,
        'summary' => $job->summary,
        'items' => $job->items ?? [],
        'error' => $job->error,
    ]);
});

Route::get('/attendance-logs', function (Illuminate\Http\Request $request) {
    $query = AttendanceLog::query();

    if ($request->query('status')) {
        $query->where('status_sync', $request->query('status'));
    }

    if ($request->query('machine_id')) {
        $query->where('machine_id', $request->query('machine_id'));
    }

    if ($request->query('search')) {
        $search = $request->query('search');
        $query->where('biometric_id_lokal', 'like', "%$search%");
    }

    return $query->orderBy('created_at', 'desc')
        ->limit(500)
        ->get();
});

// Kirim manual ke Mekari Talenta (via tombol di aplikasi)
Route::post('/attendance-logs/send-pending', [AttendanceController::class, 'sendPending']);
Route::post('/attendance-logs/{id}/send', [AttendanceController::class, 'send']);

// ===== Pengaturan aplikasi =====
// Simpan banyak setting sekaligus. Hanya key yang dikenal yang diproses.
// Field password yang dikirim kosong DIABAIKAN (tidak menimpa nilai lama),
// supaya rahasia yang tak ditampilkan ke browser tidak terhapus tak sengaja.
Route::put('/settings', function (Illuminate\Http\Request $request) {
    $data = $request->validate([
        'settings' => 'required|array|min:1',
        'settings.*' => 'nullable',
    ]);

    $known = Setting::pluck('type', 'key'); // key => type
    $updated = 0;

    foreach ($data['settings'] as $key => $value) {
        if (! $known->has($key)) {
            continue;
        }

        // Jangan timpa password dengan input kosong.
        if ($known[$key] === 'password' && ($value === null || $value === '')) {
            continue;
        }

        Setting::put($key, $value);
        $updated++;
    }

    return response()->json([
        'success' => true,
        'updated' => $updated,
        'message' => "{$updated} pengaturan disimpan.",
    ]);
});

// ===== Manajemen User & Role aplikasi =====
// Hanya admin yang boleh mengelola user. Operator/viewer ditolak 403.
Route::middleware('admin')->group(function () {
    Route::get('/users', function () {
        return App\Models\User::orderBy('name')->get(['id', 'name', 'email', 'role', 'created_at']);
    });

    Route::post('/users', function (Illuminate\Http\Request $request) {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in(App\Models\User::ROLES)],
        ]);

        $data['password'] = Illuminate\Support\Facades\Hash::make($data['password']);
        $user = App\Models\User::create($data);

        return response()->json($user->only(['id', 'name', 'email', 'role', 'created_at']), 201);
    });

    Route::put('/users/{id}', function (Illuminate\Http\Request $request, $id) {
        $user = App\Models\User::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($id)],
            // Password opsional saat edit: kosong = jangan ubah.
            'password' => 'nullable|string|min:8',
            'role' => ['required', Rule::in(App\Models\User::ROLES)],
        ]);

        // Jangan biarkan admin terakhir menurunkan dirinya sendiri / user admin
        // terakhir, supaya aplikasi tak terkunci tanpa admin.
        if ($user->isAdmin() && $data['role'] !== 'admin'
            && App\Models\User::where('role', 'admin')->count() <= 1) {
            return response()->json(['message' => 'Tidak bisa menurunkan admin terakhir. Buat admin lain dulu.'], 422);
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role = $data['role'];
        if (filled($data['password'] ?? null)) {
            $user->password = Illuminate\Support\Facades\Hash::make($data['password']);
        }
        $user->save();

        return response()->json($user->only(['id', 'name', 'email', 'role', 'created_at']));
    });

    Route::delete('/users/{id}', function (Illuminate\Http\Request $request, $id) {
        $user = App\Models\User::findOrFail($id);

        if ((int) $id === (int) $request->user()->id) {
            return response()->json(['message' => 'Tidak bisa menghapus akun yang sedang login.'], 422);
        }

        if ($user->isAdmin() && App\Models\User::where('role', 'admin')->count() <= 1) {
            return response()->json(['message' => 'Tidak bisa menghapus admin terakhir.'], 422);
        }

        $user->delete();

        return response()->json(['success' => true]);
    });
});

Route::get('/dashboard-stats', function () {
    return [
        'logs_today' => AttendanceLog::whereDate('created_at', today())->count(),
        'sent_count' => AttendanceLog::where('status_sync', 'sent')->whereDate('created_at', today())->count(),
        'failed_count' => AttendanceLog::where('status_sync', 'failed')->count(),
        'duplicate_count' => AttendanceLog::where('status_sync', 'duplicate')->count(),
        'queue_pending' => AttendanceLog::where('status_sync', 'pending')->count(),
        'queue_processing' => 0,
        'queue_failed' => AttendanceLog::where('status_sync', 'failed')->count(),
    ];
});
