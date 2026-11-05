<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Brand;
use App\Models\Outlet;
use App\Services\AttendanceExceptionReport;
use App\Support\WorkDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman "Pengecualian Absensi": siapa yang perlu ditindaklanjuti per hari
 * kerja (tidak ada absen, absen sekali, menunggu review, gagal kirim, ada
 * tanda perangkat). Perhitungan di AttendanceExceptionReport.
 */
class AttendanceExceptionController extends Controller
{
    /** Rentang maksimal satu laporan (hari). */
    public const MAX_DAYS = 31;

    /** Baris detail yang dikirim ke halaman; selebihnya lewat Export CSV. */
    public const MAX_ROWS = 5000;

    public const TYPE_LABELS = [
        AttendanceExceptionReport::NO_ATTENDANCE => 'Tidak ada absen',
        AttendanceExceptionReport::SINGLE_PUNCH => 'Absen sekali',
        AttendanceExceptionReport::REVIEW_PENDING => 'Menunggu review',
        AttendanceExceptionReport::SEND_FAILED => 'Gagal kirim',
        AttendanceExceptionReport::RISK_FLAGGED => 'Ada tanda perangkat',
    ];

    public function __construct(private AttendanceExceptionReport $report) {}

    /** GET /attendance-exceptions */
    public function index(Request $request)
    {
        $this->authorize('viewAny', AttendanceLog::class);

        $filters = $this->filters($request);
        $user = $request->user();
        $result = $this->report->build($user, $filters);

        return Inertia::render('AttendanceExceptions', [
            'filters' => $filters,
            'rows' => array_slice($result['rows'], 0, self::MAX_ROWS),
            'total_rows' => count($result['rows']),
            'max_rows' => self::MAX_ROWS,
            'summary' => $result['summary'],
            'per_outlet' => $result['per_outlet'],
            'employee_count' => $result['employees'],
            'type_labels' => self::TYPE_LABELS,
            'workday_cutoff' => sprintf('%02d:%02d', intdiv(WorkDay::cutoffMinutes(), 60), WorkDay::cutoffMinutes() % 60),
            'brands' => fn () => Brand::visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'outlets' => fn () => Outlet::visibleTo($user)->orderBy('name')->get(['id', 'name', 'brand_id']),
        ]);
    }

    /** GET /attendance-exceptions/export — semua baris (tanpa batas MAX_ROWS) sebagai CSV. */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', AttendanceLog::class);

        $filters = $this->filters($request);
        $rows = $this->report->build($request->user(), $filters)['rows'];
        $name = "pengecualian-absensi-{$filters['date_from']}_{$filters['date_to']}.csv";

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // BOM supaya Excel membaca UTF-8 dengan benar.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Hari Kerja', 'Nama', 'Kode Karyawan', 'PIN', 'Brand', 'Outlet', 'Jumlah Absen', 'Pertama', 'Terakhir', 'Pengecualian']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['date'], $r['name'], $r['employee_code'], $r['biometric_id'], $r['brand_name'], $r['outlet_name'],
                    $r['punches'], $r['first_at'], $r['last_at'],
                    implode('; ', array_map(fn ($t) => self::TYPE_LABELS[$t] ?? $t, $r['types'])),
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{date_from: string, date_to: string, brand_id: ?string, outlet_id: ?string, type: ?string} */
    private function filters(Request $request): array
    {
        // Default kemarin: hari ini belum selesai, jadi tak adil dijadikan default.
        $yesterday = today()->subDay()->toDateString();

        $data = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'brand_id' => ['nullable', 'uuid'],
            'outlet_id' => ['nullable', 'uuid'],
            'type' => ['nullable', Rule::in(AttendanceExceptionReport::TYPES)],
        ], [
            'date_to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ]);

        $from = $data['date_from'] ?? ($data['date_to'] ?? $yesterday);
        $to = $data['date_to'] ?? ($data['date_from'] ?? $yesterday);

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > self::MAX_DAYS) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'date_to' => 'Rentang maksimal ' . self::MAX_DAYS . ' hari.',
            ]);
        }

        return [
            'date_from' => $from,
            'date_to' => $to,
            'brand_id' => $data['brand_id'] ?? null,
            'outlet_id' => $data['outlet_id'] ?? null,
            'type' => $data['type'] ?? null,
        ];
    }
}
