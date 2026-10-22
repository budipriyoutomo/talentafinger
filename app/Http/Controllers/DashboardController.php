<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Company;
use App\Models\Brand;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccessDelegation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Inertia;
use Throwable;

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

        // Akun karyawan tak punya urusan di admin panel; halamannya hanya /absen(/qr).
        if ($user->isEmployee()) {
            return redirect($user->homePath());
        }

        // Status LIVE dari last_seen_at; kolom `status` di DB basi (lihat /api/machines).
        $machines = Machine::visibleTo($user)->get()->each(function ($m) {
            $m->status = $m->isOnline() ? 'online' : 'offline';
        });

        return Inertia::render('Dashboard', [
            'machines' => $machines,
            'stats' => $this->buildStats($user),
        ]);
    }

    /** JSON statistik untuk polling dashboard (GET /api/dashboard-stats). */
    public function stats(Request $request)
    {
        // Sama seperti index(): akun karyawan tak punya urusan dengan dashboard
        // (angka antrean job bersifat global, bukan per outlet).
        abort_if($request->user()->isEmployee(), 403);

        return $this->buildStats($request->user());
    }

    /**
     * Angka statistik dashboard. Hitungan log ikut scope: manajer tak boleh
     * melihat hitungan log outlet lain, sekalipun hanya berupa jumlah. Angka
     * job background bersifat global (tak memuat data outlet mana pun).
     */
    private function buildStats(User $user): array
    {
        $scoped = fn () => AttendanceLog::visibleTo($user);

        return [
            'logs_today' => $scoped()->whereDate('created_at', today())->count(),
            'sent_count' => $scoped()->where('status_sync', 'sent')->whereDate('created_at', today())->count(),
            'failed_count' => $scoped()->where('status_sync', 'failed')->count(),
            'queue_pending' => $scoped()->where('status_sync', 'pending')->count(),
            // Absen foto menunggu persetujuan supervisor (di outlet wewenangnya).
            'review_pending' => $scoped()->where('status_sync', 'pending_review')->count(),
            ...$this->jobStats(),
        ];
    }

    /**
     * Kondisi antrean job background (resend absensi, sidik jari bulk, dll).
     *
     *   jobs_waiting    = menunggu diambil worker
     *   jobs_processing = sedang dikerjakan worker (hanya diketahui di driver database)
     *   jobs_failed     = job yang gagal permanen (tabel failed_jobs)
     *
     * null = tak bisa dihitung (driver tak mendukung / tabel tak terjangkau);
     * dashboard tak boleh ikut error hanya karena angka ini.
     */
    private function jobStats(): array
    {
        $waiting = $processing = $failed = null;

        try {
            if (config('queue.connections.' . config('queue.default') . '.driver') === 'database') {
                $table = DB::table(config('queue.connections.' . config('queue.default') . '.table', 'jobs'));
                $waiting = (clone $table)->whereNull('reserved_at')->count();
                $processing = (clone $table)->whereNotNull('reserved_at')->count();
            } else {
                $waiting = Queue::size('default') + Queue::size('attendance');
            }
        } catch (Throwable $e) {
            report($e);
        }

        try {
            if (str_starts_with((string) config('queue.failed.driver'), 'database')) {
                $failed = DB::table(config('queue.failed.table', 'failed_jobs'))->count();
            }
        } catch (Throwable $e) {
            report($e);
        }

        return [
            'jobs_waiting' => $waiting,
            'jobs_processing' => $processing,
            'jobs_failed' => $failed,
        ];
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

        // Filter aktif dikirim apa adanya ke scope applyFilters(); di sanalah
        // status di-whitelist dan brand/outlet diterjemahkan. Bentuk array ini
        // sama persis dengan yang dikirim tombol "Kirim Ulang Semua Gagal",
        // supaya tabel dan aksi kirim tak bisa berbeda tafsir.
        $filters = AttendanceLog::filtersFromRequest($request);

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

        $paginator = AttendanceLog::visibleTo($user)
            ->with(['machine', 'employee', 'outlet', 'kiosk', 'reviewer'])
            ->applyFilters($filters)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn($log) => [
                'id' => $log->id,
                'machine_id' => $log->machine_id,
                'source' => $log->source,
                'biometric_id_lokal' => $log->biometric_id_lokal,
                // Jam DINDING apa adanya (jam lokal perangkat, sama dengan yang
                // dikirim ke Talenta). Jangan kirim objek tanggal: ia ter-serialize
                // sebagai UTC lalu dikonversi ulang oleh zona waktu browser, sehingga
                // jam yang tampil bergeser bila browser admin bukan WIB.
                'timestamp' => $log->timestamp?->format('Y-m-d H:i:s'),
                'status_sync' => $log->status_sync,
                'error_message' => $log->error_message,
                // Log manual tahu karyawannya langsung; log mesin di-resolve via PIN.
                'employee_name' => $log->isManual()
                    ? $log->employee?->name
                    : $employeeByPin->get($log->biometric_id_lokal)?->name,
                // Detail absen karyawan: foto atau QR (null untuk log mesin).
                'manual' => $log->isManual() ? [
                    'outlet_name' => $log->outlet?->name,
                    // Tablet tempat QR di-scan (absen QR saja; null bila kiosk sudah dihapus).
                    'kiosk_name' => $log->kiosk?->name,
                    'distance_meters' => $log->distance_meters,
                    'accuracy_meters' => $log->accuracy_meters,
                    'latitude' => $log->latitude,
                    'longitude' => $log->longitude,
                    'photo_url' => "/api/attendance-logs/{$log->id}/photo",
                    'reviewed_by' => $log->reviewer?->name,
                    'reviewed_at' => $log->reviewed_at?->format('Y-m-d H:i:s'),
                    'review_note' => $log->review_note,
                ] : null,
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
            // Isi dropdown ditulis sebagai closure, bukan nilai langsung. Inertia
            // baru mengeksekusi closure kalau prop-nya memang ikut dikirim, jadi
            // pindah halaman (partial reload `only: ['logs','pagination']`) tidak
            // lagi ikut menembak tiga query ini. Pada load penuh tetap terkirim.
            'machines' => fn () => Machine::visibleTo($user)->get(),
            'brands' => fn () => Brand::visibleTo($user)->orderBy('name')->get(['id', 'name', 'company_id']),
            'outlets' => fn () => Outlet::visibleTo($user)->orderBy('name')->get(['id', 'name', 'brand_id']),
            'filters' => $filters,
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
                    'allow_manual_attendance' => $e->allow_manual_attendance,
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
     * Tab setting (kredensial Talenta) butuh setting.manage, tab user butuh
     * user.manage, tab role butuh role.manage.
     */
    public function settings(Request $request)
    {
        // Tiga tab, tiga permission: halaman terbuka bila punya salah satunya,
        // dan tiap tab hanya menerima datanya bila izinnya ada.
        $user = $request->user();
        $canSettings = $user->hasPermission('setting.manage');
        $canUsers = $user->hasPermission('user.manage');
        $canRoles = $user->hasPermission('role.manage');

        if (! $canSettings && ! $canUsers && ! $canRoles) {
            $this->authorizePermission($request, 'setting.manage');
        }

        return Inertia::render('Settings', [
            'groups' => $canSettings ? $this->settingGroups() : [],
            ...($canUsers ? $this->userManagementProps($user) : []),
            // Daftar role dibutuhkan tab User (pilihan role) maupun tab Role.
            'roles' => $canUsers || $canRoles ? RoleController::listFor($user) : [],
            'permissionCatalog' => $canRoles ? $this->permissionCatalog($user) : [],
        ]);
    }

    /** Katalog permission untuk form role, ditandai mana yang boleh dicentang viewer. */
    private function permissionCatalog(User $viewer): array
    {
        $grantable = AccessDelegation::for($viewer)->grantablePermissions();

        // Key permission mengandung titik, jadi TIDAK bisa diambil lewat
        // config("permissions.permissions.{$key}") (dibaca sebagai path bersarang -> null).
        $labels = config('permissions.permissions', []);
        $descriptions = config('permissions.descriptions', []);
        $requires = config('permissions.requires', []);

        return collect(Role::assignablePermissions())
            ->map(fn ($key) => [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
                'description' => $descriptions[$key] ?? null,
                'requires' => $requires[$key] ?? [],
                'grantable' => in_array($key, $grantable, true),
            ])
            ->all();
    }

    /** Isi tab "Setting Aplikasi". */
    private function settingGroups()
    {
        return Setting::orderBy('id')->get()
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
    }

    /** Isi tab "User & Role". */
    private function userManagementProps(User $viewer): array
    {
        return [
            // Hanya user yang boleh dikelola viewer (lihat AccessDelegation).
            'users' => UserController::listFor($viewer),
            // Pilihan karyawan untuk ditautkan ke akun ber-role `employee`,
            // disaring ke scope pengelola.
            'employees' => Employee::visibleTo($viewer)->orderBy('name')
                ->get(['id', 'name', 'biometric_id', 'is_active', 'allow_manual_attendance']),
            // Pohon organisasi (disaring ke scope pengelola) untuk menugaskan scope.
            'companies' => $this->orgTree($viewer),
        ];
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
