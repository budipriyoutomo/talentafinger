<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Company;
use App\Models\Brand;
use App\Models\Outlet;
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
            ->withCount(['attendanceLogs'])
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
                // Status jalur TCP 4370 (server -> mesin), terpisah dari ADMS.
                // null = belum/basi, true = ready, false = tak terjangkau.
                'tcp_ready' => $m->tcpReady(),
                'tcp_checked_at' => $m->tcp_checked_at,
                'tcp_latency_ms' => $m->tcp_latency_ms,
                'tcp_error' => $m->tcp_error,
                'logs_count' => $m->attendance_logs_count,
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

        // Preload semua karyawan ber-Biometric ID sekali, dipetakan per PIN, supaya
        // nama karyawan log bisa di-resolve tanpa query berulang (hindari N+1).
        $employeeByPin = Employee::whereNotNull('biometric_id')
            ->get()
            ->keyBy('biometric_id');

        // Filter brand/outlet bekerja lewat karyawan: log tak menyimpan brand/outlet
        // langsung, jadi ambil PIN (biometric_id) karyawan pada brand/outlet terpilih
        // lalu batasi query log ke PIN tsb.
        $orgPins = null;
        if ($brandId || $outletId) {
            $orgPins = Employee::query()
                ->whereNotNull('biometric_id')
                ->when($outletId, fn($q) => $q->whereHas('outlets', fn($qq) => $qq->where('outlets.id', $outletId)))
                ->when($brandId && ! $outletId,
                    fn($q) => $q->whereHas('outlets', fn($qq) => $qq->where('brand_id', $brandId)))
                ->pluck('biometric_id');
        }

        $perPage = (int) $request->query('per_page', 100);
        $perPage = max(25, min($perPage, 500)); // jaga-jaga dari nilai ekstrem

        $paginator = AttendanceLog::with('machine')
            ->when($machineId, fn($q) => $q->where('machine_id', $machineId))
            ->when($dateFrom, fn($q) => $q->whereDate('timestamp', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('timestamp', '<=', $dateTo))
            ->when($orgPins !== null, function ($q) use ($orgPins) {
                // Tak ada karyawan yang cocok -> hasil kosong.
                if ($orgPins->isEmpty()) {
                    $q->whereRaw('1 = 0');
                    return;
                }
                $q->whereIn('biometric_id_lokal', $orgPins->all());
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
                'employee_name' => $employeeByPin->get($log->biometric_id_lokal)?->name,
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
     * Halaman Employees. Identitas karyawan untuk absensi & sidik jari memakai
     * Biometric ID (PIN global) — tidak ada lagi mapping per-mesin.
     */
    public function employeeManagement()
    {
        return Inertia::render('EmployeeManagement', [
            'employees' => Employee::query()
                ->withCount('biometricTemplates')
                ->withMax('biometricTemplates', 'enrolled_at')
                ->with('outlets.brand.company')
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
                    'fingerprints_count' => $e->biometric_templates_count,
                    'fingerprints_enrolled_at' => $e->biometric_templates_max_enrolled_at,
                    // Banyak outlet per karyawan; brand & company tersirat per outlet.
                    'outlets' => $e->outlets
                        ->sortBy('name')
                        ->map(fn($o) => [
                            'id' => $o->id,
                            'name' => $o->name,
                            'brand_id' => $o->brand?->id,
                            'brand_name' => $o->brand?->name,
                            'company_id' => $o->brand?->company?->id,
                            'company_name' => $o->brand?->company?->name,
                        ])->values(),
                ]),
            // Hierarki untuk dropdown cascading di form karyawan + panel struktur.
            'companies' => Company::with(['brands' => fn($q) => $q->orderBy('name'),
                    'brands.outlets' => fn($q) => $q->orderBy('name')])
                ->orderBy('name')
                ->get(),
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

        // Peta PIN (biometric_id) -> data karyawan, untuk mencocokkan user yang
        // dibaca LIVE dari mesin dengan master & menampilkan Brand - Outlet-nya.
        $employeesByPin = Employee::whereNotNull('biometric_id')
            ->with('outlets.brand')
            ->get()
            ->mapWithKeys(fn($e) => [(string) $e->biometric_id => [
                'id' => $e->id,
                'name' => $e->name,
                'outlets' => $e->outlets
                    ->sortBy('name')
                    ->map(fn($o) => [
                        'outlet_name' => $o->name,
                        'brand_name' => $o->brand?->name,
                    ])->values(),
            ]]);

        return Inertia::render('Fingerprints', [
            'machines' => $machines,
            'employeesByPin' => $employeesByPin,
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
                // Jangan bocorkan rahasia penuh ke browser; nilai password tetap
                // dikirim kosong (input mengganti, bukan menampilkan).
                'value' => $s->type === 'password' ? '' : $s->value,
                'is_set' => filled($s->value),
                // Pratinjau ter-mask (3 depan + 4 belakang) hanya untuk password
                // yang sudah terisi, supaya admin tahu nilai yang tersimpan.
                'preview' => $s->type === 'password' ? $this->maskSecret($s->value) : null,
            ])
            ->groupBy('group');

        return Inertia::render('Settings', [
            'groups' => $groups,
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'created_at']),
            'roles' => User::ROLES,
        ]);
    }

    /**
     * Mask rahasia untuk pratinjau: tampilkan 3 karakter depan + 4 belakang,
     * sisanya disamarkan. Untuk nilai pendek (<= 8 karakter) seluruhnya
     * disamarkan agar tidak membocorkan terlalu banyak.
     */
    private function maskSecret(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $len = mb_strlen($value);

        if ($len <= 8) {
            return str_repeat('•', $len);
        }

        return mb_substr($value, 0, 3).'••••'.mb_substr($value, -4);
    }

}
