<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Brand;
use App\Models\Employee;
use App\Models\Machine;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Halaman "Absen per Mesin": karyawan absen di mesin mana saja, lengkap dengan
 * identitas mesinnya. Murni laporan baca-saja di atas attendance_logs.
 *
 * Hanya log MESIN yang dihitung (absen foto tak punya mesin). Log mesin tak
 * menyimpan employee_id, jadi karyawan di-resolve lewat PIN
 * (biometric_id_lokal = employees.biometric_id) — sama seperti halaman Log.
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
            ...AttendanceLog::filtersFromRequest($request),
            // Halaman ini tak punya tab status/sumber: selalu log mesin, semua status
            // kecuali EXCLUDED_STATUSES.
            'status' => null,
            'source' => AttendanceLog::SOURCE_MACHINE,
        ];
        $filters['date_from'] ??= today()->subDays(self::DEFAULT_DAYS - 1)->toDateString();
        $filters['date_to'] ??= today()->toDateString();

        // Satu baris per pasangan (PIN, mesin). Jumlahnya kecil (karyawan x mesin
        // tempat ia absen), jadi aman diolah di PHP setelahnya. Scope akses lewat
        // visibleTo(), filter panel lewat applyFilters() yang sama dengan halaman Log.
        $pairs = AttendanceLog::visibleTo($user)
            ->applyFilters($filters)
            ->whereNotNull('machine_id')
            ->whereNotIn('status_sync', self::EXCLUDED_STATUSES)
            ->groupBy('biometric_id_lokal', 'machine_id')
            ->selectRaw('biometric_id_lokal, machine_id, COUNT(*) AS total, MIN(timestamp) AS first_at, MAX(timestamp) AS last_at')
            ->toBase()
            ->get();

        $employeeByPin = Employee::visibleTo($user)
            ->whereIn('biometric_id', $pairs->pluck('biometric_id_lokal')->unique()->filter()->values())
            ->get()
            ->keyBy(fn ($e) => (string) $e->biometric_id);

        // Identitas lengkap hanya untuk mesin yang muncul di hasil. Tanpa
        // visibleTo() lagi: mesinnya sudah lolos scope lewat log di atas.
        $machines = Machine::with('outlet.brand.company')
            ->whereIn('id', $pairs->pluck('machine_id')->unique()->values())
            ->get()
            ->mapWithKeys(fn (Machine $m) => [$m->id => $this->machineIdentity($m)]);

        $rows = $pairs
            ->groupBy(fn ($p) => (string) $p->biometric_id_lokal)
            ->map(function ($items, string $pin) use ($employeeByPin) {
                $employee = $employeeByPin->get($pin);
                $perMachine = $items
                    ->map(fn ($p) => [
                        'machine_id' => $p->machine_id,
                        'total' => (int) $p->total,
                        'first_at' => $this->wallClock($p->first_at),
                        'last_at' => $this->wallClock($p->last_at),
                    ])
                    ->sortByDesc('last_at')
                    ->values();

                return [
                    'pin' => $pin,
                    'employee_id' => $employee?->id,
                    'name' => $employee?->name,
                    'employee_code' => $employee?->employee_code,
                    'is_active' => $employee?->is_active,
                    'machines_count' => $perMachine->count(),
                    'total' => $perMachine->sum('total'),
                    'last_at' => $perMachine->max('last_at'),
                    'machines' => $perMachine,
                ];
            })
            // Karyawan dikenal dulu (urut nama), PIN tak dikenal di belakang.
            ->sortBy(fn ($r) => [$r['name'] === null ? 1 : 0, mb_strtolower($r['name'] ?? $r['pin'])])
            ->values();

        return Inertia::render('EmployeePresence', [
            'rows' => $rows,
            'machineIdentities' => $machines,
            'filters' => $filters,
            'machines' => fn () => Machine::visibleTo($user)->orderBy('name')->get(['id', 'name', 'serial_number']),
            'brands' => fn () => Brand::visibleTo($user)->orderBy('name')->get(['id', 'name', 'company_id']),
            'outlets' => fn () => Outlet::visibleTo($user)->orderBy('name')->get(['id', 'name', 'brand_id']),
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
