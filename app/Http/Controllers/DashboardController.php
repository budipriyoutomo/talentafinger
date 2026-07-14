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

/**
 * Halaman-halaman Inertia. Sama seperti API, tiap halaman memeriksa dua hal:
 * permission (boleh buka halamannya) dan scope (data outlet mana yang tampil).
 * Menyembunyikan menu di sidebar saja tidak cukup — URL-nya masih bisa diketik.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Status LIVE dari last_seen_at; kolom `status` di DB basi (lihat /api/machines).
        $machines = Machine::visibleTo($user)->get()->each(function ($m) {
            $m->status = $m->isOnline() ? 'online' : 'offline';
        });

        // Angka statistik ikut scope: manajer tak boleh melihat hitungan log
        // outlet lain, sekalipun hanya berupa jumlah.
        $scoped = fn () => AttendanceLog::visibleTo($user);

        $stats = [
            'logs_today' => $scoped()->whereDate('created_at', today())->count(),
            'sent_count' => $scoped()->where('status_sync', 'sent')->whereDate('created_at', today())->count(),
            'failed_count' => $scoped()->where('status_sync', 'failed')->count(),
            'queue_pending' => $scoped()->where('status_sync', 'pending')->count(),
            'queue_processing' => 0,
            'queue_failed' => $scoped()->where('status_sync', 'failed')->count(),
        ];

        return Inertia::render('Dashboard', [
            'machines' => $machines,
            'stats' => $stats,
        ]);
    }

    public function machines(Request $request)
    {
        $this->authorize('viewAny', Machine::class);

        $user = $request->user();

        $machines = Machine::visibleTo($user)
            ->with('outlet.brand.company')
            ->withCount(['attendanceLogs'])
            ->orderBy('name')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'serial_number' => $m->serial_number,
                'name' => $m->name,
                // Penempatan organisasi mesin. Null = belum di-assign outlet.
                'outlet_id' => $m->outlet_id,
                'outlet_name' => $m->outlet?->name,
                'brand_name' => $m->outlet?->brand?->name,
                'company_name' => $m->outlet?->brand?->company?->name,
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
            // Pohon organisasi untuk dropdown cascading Company -> Brand -> Outlet
            // di form mesin. Ikut disaring supaya nama outlet perusahaan lain tak
            // bocor lewat dropdown.
            'companies' => $this->orgTree($user),
        ]);
    }

    public function attendanceLogs(Request $request)
    {
        $this->authorize('viewAny', AttendanceLog::class);

        $user = $request->user();

        $machineId = $request->query('machine_id');
        $brandId = $request->query('brand_id');
        $outletId = $request->query('outlet_id');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        // Tab "Gagal" mengirim status=failed; whitelist supaya tak sembarang nilai.
        $status = $request->query('status');
        $status = in_array($status, ['pending', 'sent', 'failed', 'duplicate'], true) ? $status : null;

        // Preload karyawan ber-Biometric ID sekali, dipetakan per PIN, supaya nama
        // karyawan log bisa di-resolve tanpa query berulang (hindari N+1).
        $employeeByPin = Employee::visibleTo($user)
            ->whereNotNull('biometric_id')
            ->get()
            ->keyBy('biometric_id');

        // Ukuran halaman dari pemilih "Rows per page" di UI; whitelist supaya tak
        // ada nilai ekstrem yang bikin query berat.
        $perPage = (int) $request->query('per_page', 100);
        $perPage = in_array($perPage, [10, 50, 100, 250, 500], true) ? $perPage : 100;

        // Filter brand/outlet mengikuti outlet MESIN — sama dengan dasar yang
        // dipakai pembatasan akses, jadi tak ada dua definisi "outlet-nya log".
        // Filter dari browser tak perlu diperiksa terhadap scope: visibleTo()
        // sudah membatasi lebih dulu, jadi menebak outlet_id orang lain hanya
        // menghasilkan daftar kosong, bukan kebocoran.
        $paginator = AttendanceLog::visibleTo($user)
            ->with('machine')
            ->when($machineId, fn($q) => $q->where('machine_id', $machineId))
            ->when($status, fn($q) => $q->where('status_sync', $status))
            ->when($dateFrom, fn($q) => $q->whereDate('timestamp', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('timestamp', '<=', $dateTo))
            ->when($outletId, fn($q) => $q->whereHas('machine', fn($qq) => $qq->where('outlet_id', $outletId)))
            ->when($brandId && ! $outletId, fn($q) => $q->whereHas(
                'machine.outlet',
                fn($qq) => $qq->where('brand_id', $brandId),
            ))
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
            'machines' => Machine::visibleTo($user)->get(),
            'brands' => Brand::visibleTo($user)->orderBy('name')->get(['id', 'name', 'company_id']),
            'outlets' => Outlet::visibleTo($user)->orderBy('name')->get(['id', 'name', 'brand_id']),
            'filters' => [
                'machine_id' => $machineId,
                'brand_id' => $brandId,
                'outlet_id' => $outletId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'status' => $status,
            ],
        ]);
    }

    /**
     * Halaman Employees. Identitas karyawan untuk absensi & sidik jari memakai
     * Biometric ID (PIN global) — tidak ada lagi mapping per-mesin.
     */
    public function employeeManagement(Request $request)
    {
        $this->authorize('viewAny', Employee::class);

        $user = $request->user();

        return Inertia::render('EmployeeManagement', [
            'employees' => Employee::visibleTo($user)
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
                    // Outlet di luar scope user sengaja TIDAK ditampilkan — ia tak
                    // perlu tahu karyawannya juga terdaftar di outlet perusahaan lain.
                    'outlets' => $e->outlets
                        ->filter(fn($o) => $user->canAccessOutlet($o->id))
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
            'companies' => $this->orgTree($user),
            'machines' => Machine::visibleTo($user)->get(),
        ]);
    }

    public function fingerprints(Request $request)
    {
        $this->authorizePermission($request, 'fingerprint.view');

        $user = $request->user();

        // Daftar user/sidik jari diambil LIVE dari mesin (TCP 4370) di sisi
        // frontend, jadi controller cukup mengirim daftar mesin + status IP-nya.
        $machines = Machine::visibleTo($user)->orderBy('name')->get()->map(fn($m) => [
            'id' => $m->id,
            'name' => $m->name,
            'serial_number' => $m->serial_number,
            'ip_address' => $m->ip_address,
            'sdk_port' => $m->sdk_port,
            'is_active' => $m->is_active,
        ]);

        // Peta PIN (biometric_id) -> data karyawan, untuk mencocokkan user yang
        // dibaca LIVE dari mesin dengan master & menampilkan Brand - Outlet-nya.
        $employeesByPin = Employee::visibleTo($user)
            ->whereNotNull('biometric_id')
            ->with('outlets.brand')
            ->get()
            ->mapWithKeys(fn($e) => [(string) $e->biometric_id => [
                'id' => $e->id,
                'name' => $e->name,
                'outlets' => $e->outlets
                    ->filter(fn($o) => $user->canAccessOutlet($o->id))
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
     *
     * Admin-only: memuat kredensial Talenta dan daftar seluruh user.
     */
    public function settings(Request $request)
    {
        $this->authorizePermission($request, 'setting.manage');

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
            // Scope tiap user ikut dikirim supaya form bisa mencentang ulang
            // Company/Brand/Outlet yang jadi wewenangnya.
            'users' => User::with('dataScopes')->orderBy('name')->get()->map(fn (User $u) => [
                ...$u->only(['id', 'name', 'email', 'role', 'created_at']),
                'company_ids' => $u->dataScopes->where('scope_type', 'company')->pluck('scope_id')->values(),
                'brand_ids' => $u->dataScopes->where('scope_type', 'brand')->pluck('scope_id')->values(),
                'outlet_ids' => $u->dataScopes->where('scope_type', 'outlet')->pluck('scope_id')->values(),
            ]),
            'roles' => User::ROLES,
            // Admin melihat seluruh pohon organisasi untuk menugaskan scope.
            'companies' => $this->orgTree($request->user()),
        ]);
    }

    /** Pohon Company -> Brand -> Outlet, disaring ke scope user. */
    private function orgTree(User $user)
    {
        return Company::visibleTo($user)
            ->with([
                'brands' => fn ($q) => $q->visibleTo($user)->orderBy('name'),
                'brands.outlets' => fn ($q) => $q->visibleTo($user)->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();
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
