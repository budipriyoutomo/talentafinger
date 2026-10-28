<?php

namespace App\Http\Controllers;

use App\Models\AttendanceKiosk;
use App\Models\AttendanceLog;
use App\Models\Brand;
use App\Models\Employee;
use App\Models\Machine;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Halaman "Absen per Mesin": karyawan absen di mana saja, lengkap dengan
 * identitas tempatnya. Murni laporan baca-saja di atas attendance_logs.
 *
 * Bisa difilter per SUMBER (mesin / foto / QR; kosong = semua). Log mesin tak
 * menyimpan employee_id, jadi karyawannya di-resolve lewat PIN
 * (biometric_id_lokal = employees.biometric_id) — sama seperti halaman Log;
 * log foto/QR langsung lewat employee_id, tempatnya = outlet (+ kiosk QR).
 */
class EmployeePresenceController extends Controller
{
    /**
     * Status yang tidak dihitung sebagai "absen di mesin itu": duplikat memang
     * kiriman ulang mesin atas absen yang sama, ditolak hanya ada di log manual.
     */
    private const EXCLUDED_STATUSES = ['duplicate', 'rejected'];

    /** Rentang default bila tanggal tak diisi: 30 hari terakhir. */
    private const DEFAULT_DAYS = 30;

    public function index(Request $request)
    {
        $this->authorize('viewAny', AttendanceLog::class);

        $user = $request->user();

        $filters = [
            // `source` ikut dari sini (di-whitelist; kosong = semua sumber).
            ...AttendanceLog::filtersFromRequest($request),
            // Halaman ini tak punya tab status: semua status kecuali EXCLUDED_STATUSES.
            'status' => null,
            'employee_id' => Str::isUuid($request->input('employee_id')) ? $request->input('employee_id') : null,
        ];
        $filters['date_from'] ??= today()->subDays(self::DEFAULT_DAYS - 1)->toDateString();
        $filters['date_to'] ??= today()->toDateString();

        // Filter karyawan: log mesin dicocokkan lewat PIN-nya, log foto/QR lewat
        // employee_id. Karyawan di luar wewenang = hasil kosong.
        $picked = $filters['employee_id']
            ? Employee::visibleTo($user)->whereKey($filters['employee_id'])->first(['id', 'biometric_id'])
            : null;

        // Satu baris per (karyawan, tempat, tanggal): absen pertama hari itu =
        // masuk, terakhir = keluar. Tempat = mesin (log mesin) atau outlet + kiosk
        // (log foto/QR). Scope akses lewat visibleTo(), filter panel lewat
        // applyFilters() yang sama dengan halaman Log.
        $query = AttendanceLog::visibleTo($user)
            ->applyFilters($filters)
            ->whereNotIn('status_sync', self::EXCLUDED_STATUSES)
            ->when($filters['employee_id'] && ! $picked, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($picked, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('employee_id', $picked->id)
                ->when($picked->biometric_id, fn ($m) => $m->orWhere(fn ($mm) => $mm
                    ->whereNull('employee_id')
                    ->where('biometric_id_lokal', $picked->biometric_id)))));
        $day = 'DATE(' . $query->getQuery()->getGrammar()->wrap('timestamp') . ')';

        $keys = ['source', 'employee_id', 'biometric_id_lokal', 'machine_id', 'outlet_id', 'kiosk_id'];
        $groups = $query
            ->groupBy($keys)
            ->groupByRaw($day)
            ->selectRaw(implode(', ', $keys) . ", {$day} AS log_date, COUNT(*) AS total, MIN(timestamp) AS first_at, MAX(timestamp) AS last_at")
            ->toBase()
            ->get();

        [$employeeGroups, $machineGroups] = $groups->partition(fn ($g) => $g->employee_id !== null);

        $employeeByPin = Employee::visibleTo($user)
            ->whereIn('biometric_id', $machineGroups->pluck('biometric_id_lokal')->unique()->filter()->values())
            ->get()
            ->keyBy(fn ($e) => (string) $e->biometric_id);

        // Karyawan log foto/QR tak perlu visibleTo(): lognya sudah lolos scope
        // lewat outlet tempat absen (sama seperti halaman Log menampilkannya).
        $employeeById = Employee::whereIn('id', $employeeGroups->pluck('employee_id')->unique()->values())
            ->get()
            ->keyBy('id');

        $outletPlaces = Outlet::with('brand.company')
            ->whereIn('id', $employeeGroups->pluck('outlet_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');
        $kioskNames = AttendanceKiosk::whereIn('id', $employeeGroups->pluck('kiosk_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        // Identitas lengkap hanya untuk mesin yang muncul di hasil. Tanpa
        // visibleTo() lagi: mesinnya sudah lolos scope lewat log di atas.
        $machines = Machine::with('outlet.brand.company')
            ->whereIn('id', $machineGroups->pluck('machine_id')->filter()->unique()->values())
            ->get()
            ->mapWithKeys(fn (Machine $m) => [$m->id => $this->machineIdentity($m)]);

        $rows = $groups
            ->map(function ($p) use ($employeeByPin, $employeeById, $outletPlaces, $kioskNames) {
                $pin = (string) $p->biometric_id_lokal;
                $employee = $p->employee_id !== null
                    ? $employeeById->get($p->employee_id)
                    : $employeeByPin->get($pin);
                $date = substr((string) $p->log_date, 0, 10);
                $outlet = $p->outlet_id ? $outletPlaces->get($p->outlet_id) : null;

                return [
                    'key' => implode('|', [$p->source, $p->employee_id ?? $pin, $p->machine_id ?? $p->outlet_id, $p->kiosk_id, $date]),
                    'date' => $date,
                    'source' => $p->source,
                    'pin' => $pin,
                    'employee_id' => $employee?->id,
                    'name' => $employee?->name,
                    'employee_code' => $employee?->employee_code,
                    'machine_id' => $p->machine_id,
                    // Tempat absen foto/QR (log mesin memakai machineIdentities).
                    'place' => $p->machine_id ? null : [
                        'outlet_name' => $outlet?->name,
                        'brand_name' => $outlet?->brand?->name,
                        'company_name' => $outlet?->brand?->company?->name,
                        'kiosk_name' => $p->kiosk_id ? $kioskNames->get($p->kiosk_id) : null,
                    ],
                    'total' => (int) $p->total,
                    'check_in' => $this->wallClock($p->first_at),
                    'check_out' => $this->wallClock($p->last_at),
                ];
            })
            // Tanggal terbaru dulu; dalam satu hari karyawan dikenal dulu (urut
            // nama), PIN tak dikenal di belakang, lalu urut jam masuk.
            ->sort(fn ($a, $b) => [$b['date'], $a['name'] === null ? 1 : 0, mb_strtolower($a['name'] ?? $a['pin']), $a['check_in']]
                <=> [$a['date'], $b['name'] === null ? 1 : 0, mb_strtolower($b['name'] ?? $b['pin']), $b['check_in']])
            ->values();

        return Inertia::render('EmployeePresence', [
            'rows' => $rows,
            'machineIdentities' => $machines,
            'filters' => $filters,
            'machines' => fn () => Machine::visibleTo($user)->orderBy('name')->get(['id', 'name', 'serial_number']),
            'brands' => fn () => Brand::visibleTo($user)->orderBy('name')->get(['id', 'name', 'company_id']),
            'outlets' => fn () => Outlet::visibleTo($user)->orderBy('name')->get(['id', 'name', 'brand_id']),
            // Semua karyawan: log mesin dicocokkan lewat PIN, log foto/QR lewat employee_id.
            'employees' => fn () => Employee::visibleTo($user)
                ->orderBy('name')
                ->get(['id', 'name', 'employee_code', 'biometric_id']),
        ]);
    }

    /** Identitas mesin yang ditampilkan: sama dengan kolom di halaman Machines. */
    private function machineIdentity(Machine $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'serial_number' => $m->serial_number,
            'outlet_name' => $m->outlet?->name,
            'brand_name' => $m->outlet?->brand?->name,
            'company_name' => $m->outlet?->brand?->company?->name,
            'location' => $m->location,
            'ip_address' => $m->ip_address,
            'sdk_port' => $m->sdk_port,
            'is_active' => $m->is_active,
            'is_online' => $m->isOnline(),
            'tcp_ready' => $m->tcpReady(),
            'last_seen_at' => $m->last_seen_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * MIN/MAX mentah dari DB berupa string jam dinding (jam lokal perangkat).
     * Potong ke "Y-m-d H:i:s" tanpa konversi zona waktu, seperti halaman Log.
     */
    private function wallClock(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 19);
    }
}
