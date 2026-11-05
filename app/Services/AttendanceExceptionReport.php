<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Machine;
use App\Models\Outlet;
use App\Models\User;
use App\Support\WorkDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Laporan pengecualian absensi (R3): "siapa yang perlu ditindaklanjuti" per
 * karyawan per HARI KERJA (lihat WorkDay).
 *
 * Aplikasi ini tidak punya data jadwal/shift (milik Talenta), jadi labelnya
 * "Tidak ada absen", bukan "mangkir": karyawan bisa saja sedang libur.
 *
 * Aturan:
 *  - Status duplicate & rejected diabaikan (sama dengan halaman Absen per Mesin).
 *  - Log mesin dicocokkan lewat PIN (biometric_id_lokal = employees.biometric_id),
 *    log foto/QR lewat employee_id.
 *  - Karyawan multi-outlet dihitung sekali per hari; hadir bila ada log di
 *    outlet MANA PUN (termasuk outlet di luar scope user), supaya tak muncul
 *    "tidak ada absen" palsu. Jenis berbasis status (review/gagal/flag) hanya
 *    dihitung dari log yang memang boleh dilihat user.
 *  - Log berjarak < 5 menit dihitung satu (scan berulang).
 *  - Karyawan tanpa outlet tidak ikut (tak ada outlet tempat ia "aktif").
 */
class AttendanceExceptionReport
{
    public const NO_ATTENDANCE = 'no_attendance';
    public const SINGLE_PUNCH = 'single_punch';
    public const REVIEW_PENDING = 'review_pending';
    public const SEND_FAILED = 'send_failed';
    public const RISK_FLAGGED = 'risk_flagged';

    public const TYPES = [
        self::NO_ATTENDANCE,
        self::SINGLE_PUNCH,
        self::REVIEW_PENDING,
        self::SEND_FAILED,
        self::RISK_FLAGGED,
    ];

    public const EXCLUDED_STATUSES = ['duplicate', 'rejected'];

    /** Jeda minimal antar-absen yang dihitung terpisah. */
    public const DEDUPE_SECONDS = 5 * 60;

    /**
     * @param  array{date_from: string, date_to: string, brand_id?: ?string, outlet_id?: ?string, type?: ?string}  $filters
     * @return array{rows: array<int, array>, summary: array<string, int>, per_outlet: array<int, array>, employees: int}
     */
    public function build(User $user, array $filters): array
    {
        $cutoff = WorkDay::cutoffMinutes();

        $outlets = Outlet::visibleTo($user)
            ->with('brand:id,name')
            ->when($filters['brand_id'] ?? null, fn ($q, $v) => $q->where('brand_id', $v))
            ->when($filters['outlet_id'] ?? null, fn ($q, $v) => $q->whereKey($v))
            ->orderBy('name')
            ->get(['id', 'name', 'brand_id'])
            ->keyBy('id');

        $empty = ['rows' => [], 'summary' => array_fill_keys(self::TYPES, 0), 'per_outlet' => [], 'employees' => 0];
        if ($outlets->isEmpty()) {
            return $empty;
        }
        $outletIds = $outlets->keys()->all();

        $employees = Employee::visibleTo($user)
            ->where('is_active', true)
            ->whereHas('outlets', fn ($q) => $q->whereIn('outlets.id', $outletIds))
            ->with(['outlets' => fn ($q) => $q->whereIn('outlets.id', $outletIds)->orderBy('name')->select('outlets.id', 'outlets.name')])
            ->orderBy('name')
            ->get(['id', 'name', 'employee_code', 'biometric_id']);

        if ($employees->isEmpty()) {
            return $empty;
        }

        $employeeIds = $employees->pluck('id')->all();
        $byPin = $employees->filter(fn ($e) => filled($e->biometric_id))
            ->keyBy(fn ($e) => (string) $e->biometric_id);
        $machineOutlet = Machine::pluck('outlet_id', 'id');

        [$start, $end] = WorkDay::bounds($filters['date_from'], $filters['date_to'], $cutoff);

        // days[employee_id][Y-m-d] = ['times' => int[], 'pending' => bool, 'failed' => bool, 'risk' => bool, 'outlet' => ?string]
        $days = [];

        $logs = AttendanceLog::query()
            ->where('timestamp', '>=', $start)
            ->where('timestamp', '<', $end)
            ->whereNotIn('status_sync', self::EXCLUDED_STATUSES)
            ->where(fn ($q) => $q
                ->whereIn('employee_id', $employeeIds)
                ->when($byPin->isNotEmpty(), fn ($qq) => $qq->orWhere(fn ($m) => $m
                    ->whereNull('employee_id')
                    ->where('source', AttendanceLog::SOURCE_MACHINE)
                    ->whereIn('biometric_id_lokal', $byPin->keys()->all()))))
            ->orderBy('timestamp')
            ->toBase()
            ->select(['employee_id', 'biometric_id_lokal', 'machine_id', 'outlet_id', 'source', 'timestamp', 'status_sync', 'risk_flags'])
            ->cursor();

        foreach ($logs as $log) {
            $employeeId = $log->employee_id ?? $byPin->get((string) $log->biometric_id_lokal)?->id;
            if ($employeeId === null) {
                continue;
            }

            $date = WorkDay::dateOf((string) $log->timestamp, $cutoff);
            $owningOutlet = $log->machine_id ? $machineOutlet->get($log->machine_id) : $log->outlet_id;
            $visible = $user->canAccessOutlet($owningOutlet);

            $day = &$days[$employeeId][$date];
            $day ??= ['times' => [], 'pending' => false, 'failed' => false, 'risk' => false, 'outlet' => null];
            $day['times'][] = CarbonImmutable::parse((string) $log->timestamp)->getTimestamp();
            $day['outlet'] ??= isset($outlets[$owningOutlet]) ? $owningOutlet : null;
            if ($visible) {
                $day['pending'] = $day['pending'] || $log->status_sync === 'pending_review';
                $day['failed'] = $day['failed'] || $log->status_sync === 'failed';
                $day['risk'] = $day['risk'] || ! in_array($log->risk_flags, [null, '', '[]', 'null'], true);
            }
            unset($day);
        }

        $dates = collect(CarbonPeriod::create($filters['date_from'], $filters['date_to']))
            ->map(fn ($d) => $d->toDateString())
            ->all();
        $typeFilter = $filters['type'] ?? null;

        $rows = [];
        $summary = array_fill_keys(self::TYPES, 0);
        $perOutlet = [];

        foreach ($dates as $date) {
            foreach ($employees as $employee) {
                $day = $days[$employee->id][$date] ?? null;
                $times = $day['times'] ?? [];
                $punches = $this->countPunches($times);

                $types = array_values(array_filter([
                    $punches === 0 ? self::NO_ATTENDANCE : null,
                    $punches === 1 ? self::SINGLE_PUNCH : null,
                    ($day['pending'] ?? false) ? self::REVIEW_PENDING : null,
                    ($day['failed'] ?? false) ? self::SEND_FAILED : null,
                    ($day['risk'] ?? false) ? self::RISK_FLAGGED : null,
                ]));

                if ($types === [] || ($typeFilter && ! in_array($typeFilter, $types, true))) {
                    continue;
                }

                // Satu outlet per baris supaya karyawan multi-outlet tak terhitung
                // dua kali: outlet absen pertama hari itu, atau outlet pertamanya.
                $outletId = $day['outlet'] ?? $employee->outlets->first()?->id;
                $outlet = $outlets->get($outletId);

                $rows[] = [
                    'key' => "{$employee->id}|{$date}",
                    'date' => $date,
                    'employee_id' => $employee->id,
                    'name' => $employee->name,
                    'employee_code' => $employee->employee_code,
                    'biometric_id' => $employee->biometric_id,
                    'outlet_id' => $outletId,
                    'outlet_name' => $outlet?->name,
                    'brand_name' => $outlet?->brand?->name,
                    'punches' => $punches,
                    'first_at' => $times ? date('H:i', min($times)) : null,
                    'last_at' => $times ? date('H:i', max($times)) : null,
                    'types' => $types,
                ];

                $perOutlet[$outletId] ??= [
                    'outlet_id' => $outletId,
                    'outlet_name' => $outlet?->name,
                    'brand_name' => $outlet?->brand?->name,
                    ...array_fill_keys(self::TYPES, 0),
                ];
                foreach ($types as $type) {
                    $summary[$type]++;
                    $perOutlet[$outletId][$type]++;
                }
            }
        }

        usort($rows, fn ($a, $b) => [$b['date'], mb_strtolower($a['name'])] <=> [$a['date'], mb_strtolower($b['name'])]);
        $perOutlet = array_values($perOutlet);
        usort($perOutlet, fn ($a, $b) => strcmp((string) $a['outlet_name'], (string) $b['outlet_name']));

        return [
            'rows' => $rows,
            'summary' => $summary,
            'per_outlet' => $perOutlet,
            'employees' => $employees->count(),
        ];
    }

    /**
     * Jumlah absen setelah scan berulang digabung: absen yang berjarak kurang
     * dari DEDUPE_SECONDS dari absen terhitung sebelumnya tidak dihitung lagi.
     *
     * @param  int[]  $times  unix timestamp (jam dinding sebagai UTC)
     */
    private function countPunches(array $times): int
    {
        sort($times);
        $count = 0;
        $last = null;

        foreach ($times as $t) {
            if ($last === null || $t - $last >= self::DEDUPE_SECONDS) {
                $count++;
                $last = $t;
            }
        }

        return $count;
    }
}
