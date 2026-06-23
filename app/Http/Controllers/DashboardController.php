<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\AttendanceLog;
use App\Models\EmployeeMapping;
use App\Models\Employee;
use App\Models\Company;
use App\Models\Brand;
use App\Models\Outlet;
use App\Models\BiometricTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        // Status LIVE dari last_seen_at; kolom `status` di DB basi (lihat /api/machines).
        $machines = Machine::all()->each(function ($m) {
            $m->status = $m->isOnline() ? 'online' : 'offline';
        });

        $stats = [
            'logs_today' => AttendanceLog::whereDate('created_at', today())->count(),
            'sent_count' => AttendanceLog::where('status_sync', 'sent')->whereDate('created_at', today())->count(),
            'failed_count' => AttendanceLog::where('status_sync', 'failed')->count(),
            'queue_pending' => AttendanceLog::where('status_sync', 'pending')->count(),
            'queue_processing' => 0,
            'queue_failed' => AttendanceLog::where('status_sync', 'failed')->count(),
        ];

        return Inertia::render('Dashboard', [
            'machines' => $machines,
            'stats' => $stats,
        ]);
    }

    public function machines()
    {
        $machines = Machine::query()
            ->withCount(['attendanceLogs', 'employeeMappings'])
            ->orderBy('name')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'serial_number' => $m->serial_number,
                'name' => $m->name,
                'location' => $m->location,
                'ip_address' => $m->ip_address,
                'sdk_port' => $m->sdk_port,
                'last_seen_at' => $m->last_seen_at,
                'is_active' => $m->is_active,
                'is_online' => $m->isOnline(),
                'logs_count' => $m->attendance_logs_count,
                'mappings_count' => $m->employee_mappings_count,
                'last_log_at' => $m->attendanceLogs()->max('timestamp'),
            ]);

        return Inertia::render('Machines', [
            'machines' => $machines,
        ]);
    }

    public function attendanceLogs(Request $request)
    {
        $machineId = $request->query('machine_id');
        $brandId = $request->query('brand_id');
        $outletId = $request->query('outlet_id');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        // Preload semua mapping (+ employee) sekali, dipetakan per machine|bio,
        // supaya tidak query berulang per baris log (hindari N+1).
        $mappingByKey = EmployeeMapping::with('employee')
            ->get()
            ->keyBy(fn($m) => $m->machine_id . '|' . $m->biometric_id_lokal);

        // Filter brand/outlet bekerja lewat karyawan: log tak menyimpan brand/outlet
        // langsung, jadi ambil pasangan (machine, biometric) milik karyawan pada
        // brand/outlet terpilih lalu batasi query log ke pasangan tsb.
        $orgMappings = null;
        if ($brandId || $outletId) {
            $employeeIds = Employee::query()
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->when($brandId && ! $outletId,
                    fn($q) => $q->whereHas('outlet', fn($qq) => $qq->where('brand_id', $brandId)))
                ->pluck('id');

            $orgMappings = EmployeeMapping::whereIn('employee_id', $employeeIds)
                ->get(['machine_id', 'biometric_id_lokal']);
        }

        $perPage = (int) $request->query('per_page', 100);
        $perPage = max(25, min($perPage, 500)); // jaga-jaga dari nilai ekstrem

        $paginator = AttendanceLog::with('machine')
            ->when($machineId, fn($q) => $q->where('machine_id', $machineId))
            ->when($dateFrom, fn($q) => $q->whereDate('timestamp', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('timestamp', '<=', $dateTo))
            ->when($orgMappings !== null, function ($q) use ($orgMappings) {
                // Tak ada karyawan/ mapping yang cocok -> hasil kosong.
                if ($orgMappings->isEmpty()) {
                    $q->whereRaw('1 = 0');
                    return;
                }
                $q->where(function ($qq) use ($orgMappings) {
                    foreach ($orgMappings as $m) {
                        $qq->orWhere(fn($w) => $w
                            ->where('machine_id', $m->machine_id)
                            ->where('biometric_id_lokal', $m->biometric_id_lokal));
                    }
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn($log) => [
                'id' => $log->id,
                'machine_id' => $log->machine_id,
                'biometric_id_lokal' => $log->biometric_id_lokal,
                'timestamp' => $log->timestamp,
                'status_sync' => $log->status_sync,
                'error_message' => $log->error_message,
                'employee_name' => optional(optional(
                    $mappingByKey->get($log->machine_id . '|' . $log->biometric_id_lokal)
                )->employee)->name,
            ]);

        return Inertia::render('AttendanceLogs', [
            'logs' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'machines' => Machine::all(),
            'brands' => Brand::orderBy('name')->get(['id', 'name', 'company_id']),
            'outlets' => Outlet::orderBy('name')->get(['id', 'name', 'brand_id']),
            'filters' => [
                'machine_id' => $machineId,
                'brand_id' => $brandId,
                'outlet_id' => $outletId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    /**
     * Halaman gabungan Employees + Mappings (UI bertab). Props = union dari
     * data karyawan (ber-mappings_count, sekaligus dipakai untuk dropdown
     * mapping) + daftar mapping + daftar mesin.
     */
    public function employeeManagement()
    {
        return Inertia::render('EmployeeManagement', [
            'employees' => Employee::query()
                ->withCount('mappings')
                ->withCount('biometricTemplates')
                ->withMax('biometricTemplates', 'enrolled_at')
                ->with('outlet.brand.company')
                ->orderBy('name')
                ->get()
                ->map(fn($e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'talenta_employee_id' => $e->talenta_employee_id,
                    'employee_code' => $e->employee_code,
                    'biometric_id' => $e->biometric_id,
                    'is_active' => $e->is_active,
                    'device_privilege' => $e->device_privilege,
                    'mappings_count' => $e->mappings_count,
                    'fingerprints_count' => $e->biometric_templates_count,
                    'fingerprints_enrolled_at' => $e->biometric_templates_max_enrolled_at,
                    'outlet_id' => $e->outlet_id,
                    'outlet_name' => $e->outlet?->name,
                    'brand_id' => $e->outlet?->brand?->id,
                    'brand_name' => $e->outlet?->brand?->name,
                    'company_id' => $e->outlet?->brand?->company?->id,
                    'company_name' => $e->outlet?->brand?->company?->name,
                ]),
            // Hierarki untuk dropdown cascading di form karyawan + panel struktur.
            'companies' => Company::with(['brands' => fn($q) => $q->orderBy('name'),
                    'brands.outlets' => fn($q) => $q->orderBy('name')])
                ->orderBy('name')
                ->get(),
            'mappings' => EmployeeMapping::with('employee')
                ->get()
                ->map(fn($m) => [
                    'id' => $m->id,
                    'machine_id' => $m->machine_id,
                    'biometric_id_lokal' => $m->biometric_id_lokal,
                    'employee_id' => $m->employee_id,
                    'talenta_employee_id' => $m->employee?->talenta_employee_id,
                    'employee_name' => $m->employee?->name,
                ]),
            'machines' => Machine::all(),
        ]);
    }

    public function fingerprints()
    {
        // Daftar user/sidik jari diambil LIVE dari mesin (TCP 4370) di sisi
        // frontend, jadi controller cukup mengirim daftar mesin + status IP-nya.
        $machines = Machine::orderBy('name')->get()->map(fn($m) => [
            'id' => $m->id,
            'name' => $m->name,
            'serial_number' => $m->serial_number,
            'ip_address' => $m->ip_address,
            'sdk_port' => $m->sdk_port,
            'is_active' => $m->is_active,
        ]);

        return Inertia::render('Fingerprints', [
            'machines' => $machines,
        ]);
    }

    /**
     * Halaman pengaturan aplikasi. Kelompokkan baris setting per `group`
     * untuk dirender jadi beberapa kartu di frontend. Field bertipe password
     * tidak pernah dikirim nilainya ke frontend (hanya flag terisi/tidak).
     */
    public function settings()
    {
        $groups = Setting::orderBy('id')->get()
            ->map(fn ($s) => [
                'key' => $s->key,
                'group' => $s->group,
                'type' => $s->type,
                'label' => $s->label,
                'description' => $s->description,
                // Jangan bocorkan rahasia ke browser; cukup tahu sudah terisi.
                'value' => $s->type === 'password' ? '' : $s->value,
                'is_set' => filled($s->value),
            ])
            ->groupBy('group');

        return Inertia::render('Settings', [
            'groups' => $groups,
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'created_at']),
            'roles' => User::ROLES,
        ]);
    }

}
