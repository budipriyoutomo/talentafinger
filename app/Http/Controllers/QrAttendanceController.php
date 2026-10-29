<?php

namespace App\Http\Controllers;

use App\Models\AttendanceKiosk;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Setting;
use App\Services\QrTokenService;
use App\Support\AttendancePhoto;
use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * Absen QR per outlet: karyawan scan QR dinamis di tablet kiosk pakai HP
 * sendiri, lalu kirim selfie + lokasi.
 *
 * Token QR hanya berlaku ±1 menit, sedangkan ambil lokasi + selfie bisa lebih
 * lama. Karena itu token dibuktikan SAAT SCAN, lalu "dibekukan" ke session
 * (qr_scan) selama attendance.qr_scan_ttl detik. Submit cukup memeriksa scan
 * session itu — token tak pernah dikirim ulang.
 *
 * Hasilnya log source='qr' berstatus 'pending_review', direview supervisor
 * seperti absen foto (ManualAttendanceController::review).
 */
class QrAttendanceController extends Controller
{
    private const SESSION_SCAN = 'qr_scan';

    /** Pesan error scan. Bukan flash biasa: harus selamat melewati alur login. */
    private const SESSION_ERROR = 'qr_error';

    private const SCAN_TTL_MIN = 60;
    private const SCAN_TTL_MAX = 600;
    private const SCAN_TTL_DEFAULT = 180;

    public function __construct(private QrTokenService $tokens)
    {
    }

    /**
     * GET /absen/qr — tujuan QR (?k&w&s) sekaligus halaman absen QR.
     * Di luar grup auth: tamu yang scan disimpan scan-nya dulu, lalu login.
     */
    public function scan(Request $request)
    {
        if ($request->hasAny(['k', 'w', 's'])) {
            $kiosk = $this->tokens->verify(
                $this->stringQuery($request, 'k'),
                $request->query('w'),
                $this->stringQuery($request, 's'),
            );

            if ($kiosk === null) {
                $request->session()->put(
                    self::SESSION_ERROR,
                    'QR sudah kedaluwarsa atau tidak valid. Scan ulang QR di layar outlet.',
                );
            } else {
                $request->session()->forget(self::SESSION_ERROR);
                $request->session()->put(self::SESSION_SCAN, [
                    'kiosk_id' => $kiosk->id,
                    'scanned_at' => now()->timestamp,
                ]);
            }

            // Redirect ke URL bersih: token hilang dari address bar & riwayat.
            return $this->guestToLogin($request) ?? redirect('/absen/qr');
        }

        return $this->guestToLogin($request) ?? $this->show($request);
    }

    /** Halaman absen QR (mobile-first, tanpa layout admin). */
    public function show(Request $request)
    {
        $this->authorizePermission($request, 'attendance.qr.create');

        $user = $request->user();
        $employee = $user->employee()->with('outlets')->first();
        [$scan, $kiosk] = $this->currentScan($request);

        $blocked = $this->blockedReason($employee)
            ?? ($kiosk ? $this->kioskProblem($kiosk) ?? $this->registrationProblem($employee, $kiosk) : null);

        return Inertia::render('QrCheckIn', [
            'employee' => $employee ? ['name' => $employee->name] : null,
            'scan' => $kiosk ? [
                'kiosk_name' => $kiosk->name,
                'outlet_name' => $kiosk->outlet?->name,
                'expires_in' => $this->secondsLeft($scan),
            ] : null,
            'qr_error' => $request->session()->pull(self::SESSION_ERROR),
            'blocked_reason' => $blocked,
            'radius_meters' => $this->radius(),
            'max_accuracy_meters' => $this->maxAccuracy(),
            'max_photo_kb' => AttendancePhoto::maxKb(),
            'recent' => $employee ? $this->recentLogs($employee) : [],
            'can_manual_photo' => $user->hasPermission('attendance.manual.create')
                && (bool) Setting::value('attendance.manual_photo_enabled')
                && (bool) $employee?->allow_manual_attendance,
        ]);
    }

    /** POST /api/attendance/qr — kirim absen QR (selfie + lokasi). */
    public function store(Request $request)
    {
        $this->authorizePermission($request, 'attendance.qr.create');

        $employee = $request->user()->employee()->with('outlets')->first();

        if ($reason = $this->blockedReason($employee)) {
            return response()->json(['message' => $reason], 403);
        }

        [$scan, $kiosk] = $this->currentScan($request);
        if ($kiosk === null) {
            $request->session()->forget(self::SESSION_SCAN);

            return response()->json(['message' => 'Waktu scan habis. Scan ulang QR di layar outlet.'], 422);
        }

        if ($problem = $this->kioskProblem($kiosk)) {
            return response()->json(['message' => $problem], 422);
        }

        if ($problem = $this->registrationProblem($employee, $kiosk)) {
            return response()->json(['message' => $problem], 403);
        }

        AttendancePhoto::assertUploadValid($request);
        $data = $request->validate(AttendancePhoto::rules(), AttendancePhoto::messages());

        // Akurasi GPS buruk (mis. ±1.500 m) membuat geofence tak bermakna:
        // titik tengahnya bisa kebetulan masuk radius padahal orangnya jauh.
        $maxAccuracy = $this->maxAccuracy();
        $accuracy = isset($data['accuracy']) ? (float) $data['accuracy'] : null;

        if ($accuracy === null || $accuracy > $maxAccuracy) {
            return response()->json([
                'message' => 'Sinyal lokasi kurang akurat (±' . ($accuracy !== null ? round($accuracy) : '?') . " m, maksimal ±{$maxAccuracy} m). "
                    . 'Aktifkan GPS / mode akurasi tinggi, keluar ke area terbuka, lalu ambil lokasi ulang.',
                'accuracy_meters' => $accuracy !== null ? (int) round($accuracy) : null,
                'max_accuracy_meters' => $maxAccuracy,
            ], 422);
        }

        return ManualAttendanceController::submitExclusively($employee->id, function () use ($request, $employee, $kiosk, $scan, $data, $accuracy) {
            // Geofence ke outlet KIOSK (bukan outlet terdekat): QR membuktikan karyawan
            // di depan tablet itu, lokasi HP harus ikut membuktikan hal yang sama.
            $outlet = $kiosk->outlet;
            $lat = (float) $data['latitude'];
            $lng = (float) $data['longitude'];
            $distance = (int) round(Geo::distanceMeters($lat, $lng, $outlet->latitude, $outlet->longitude));
            $radius = $this->radius();

            if ($distance > $radius) {
                return response()->json([
                    'message' => "Anda berada ±{$distance} m dari {$outlet->name}. Absen QR hanya bisa dalam radius {$radius} m.",
                    'distance_meters' => $distance,
                    'radius_meters' => $radius,
                ], 422);
            }

            $path = AttendancePhoto::store($request->file('photo'));

            // Waktu absen = waktu SCAN (bukti hadir di depan kiosk), dalam jam LOKAL
            // perangkat seperti log mesin & absen foto, karena itulah yang dikirim ke Talenta.
            $tz = Setting::value('adms.device_timezone', 'Asia/Jakarta');

            try {
                $log = AttendanceLog::create([
                    'source' => AttendanceLog::SOURCE_QR,
                    'employee_id' => $employee->id,
                    'outlet_id' => $kiosk->outlet_id,
                    'kiosk_id' => $kiosk->id,
                    'machine_id' => null,
                    'biometric_id_lokal' => $employee->biometric_id,
                    'timestamp' => Carbon::createFromTimestamp($scan['scanned_at'])->setTimezone($tz)->format('Y-m-d H:i:s'),
                    'status_sync' => 'pending_review',
                    'photo_path' => $path,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'accuracy_meters' => (int) round($accuracy),
                    'distance_meters' => $distance,
                ]);
            } catch (\Throwable $e) {
                // Jangan tinggalkan foto yatim di disk bila log gagal tersimpan.
                Storage::disk(AttendancePhoto::DISK)->delete($path);
                throw $e;
            }

            // Satu scan = satu absen.
            $request->session()->forget(self::SESSION_SCAN);

            return response()->json([
                'message' => "Absen tercatat di {$outlet->name} (±{$distance} m). Menunggu persetujuan supervisor.",
                'log' => $this->presentForEmployee($log->load(['outlet', 'kiosk'])),
            ], 201);
        });
    }

    /** Tamu -> simpan tujuan lalu ke /login. Session (termasuk scan) selamat melewati login. */
    private function guestToLogin(Request $request)
    {
        if ($request->user()) {
            return null;
        }

        $request->session()->put('url.intended', url('/absen/qr'));

        return redirect('/login');
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }

    /**
     * Scan session yang masih berlaku + kiosk-nya, atau [null, null].
     * Kiosk dimuat apa adanya (termasuk yang baru dinonaktifkan) supaya
     * pemanggil bisa memberi pesan yang jelas lewat kioskProblem().
     *
     * @return array{0: ?array, 1: ?AttendanceKiosk}
     */
    private function currentScan(Request $request): array
    {
        $scan = $request->session()->get(self::SESSION_SCAN);

        if (! is_array($scan) || ! isset($scan['kiosk_id'], $scan['scanned_at']) || $this->secondsLeft($scan) <= 0) {
            return [null, null];
        }

        $kiosk = AttendanceKiosk::with('outlet')->find($scan['kiosk_id']);

        return $kiosk ? [$scan, $kiosk] : [null, null];
    }

    private function secondsLeft(?array $scan): int
    {
        if ($scan === null) {
            return 0;
        }

        return max(0, (int) $scan['scanned_at'] + $this->scanTtl() - now()->timestamp);
    }

    /**
     * Alasan karyawan TIDAK boleh absen QR sama sekali, atau null. Ditampilkan
     * apa adanya ke karyawan, jadi ditulis sebagai petunjuk tindakan.
     */
    private function blockedReason(?Employee $employee): ?string
    {
        return match (true) {
            ! Setting::value('attendance.qr_enabled') => 'Absen QR sedang dinonaktifkan oleh admin.',
            $employee === null => 'Akun Anda belum ditautkan ke data karyawan. Hubungi admin.',
            ! $employee->is_active => 'Data karyawan Anda nonaktif. Hubungi admin.',
            default => null,
        };
    }

    /** Kiosk / outlet hasil scan tak bisa dipakai lagi. */
    private function kioskProblem(AttendanceKiosk $kiosk): ?string
    {
        $outlet = $kiosk->outlet;

        return match (true) {
            ! $kiosk->is_active || ! $kiosk->isActivated() => 'Kiosk ini sudah tidak aktif. Scan QR di kiosk lain atau hubungi admin.',
            $outlet === null || ! $outlet->is_active => 'Outlet kiosk ini nonaktif. Hubungi admin.',
            ! $outlet->hasCoordinates() => "Outlet {$outlet->name} belum punya titik koordinat. Hubungi admin.",
            default => null,
        };
    }

    /** Karyawan hanya boleh absen QR di outlet tempat ia terdaftar (pivot employee_outlet). */
    private function registrationProblem(Employee $employee, AttendanceKiosk $kiosk): ?string
    {
        return $employee->outlets->contains('id', $kiosk->outlet_id)
            ? null
            : "Anda tidak terdaftar di outlet {$kiosk->outlet?->name}. Hubungi admin.";
    }

    private function radius(): int
    {
        return max(10, (int) Setting::value('attendance.qr_radius', 100));
    }

    /** Akurasi GPS terburuk (meter) yang masih diterima. */
    private function maxAccuracy(): int
    {
        return max(10, (int) Setting::value('attendance.qr_max_accuracy', 100));
    }

    private function scanTtl(): int
    {
        $ttl = (int) Setting::value('attendance.qr_scan_ttl', self::SCAN_TTL_DEFAULT);

        return max(self::SCAN_TTL_MIN, min(self::SCAN_TTL_MAX, $ttl ?: self::SCAN_TTL_DEFAULT));
    }

    private function recentLogs(Employee $employee): array
    {
        return AttendanceLog::with(['outlet', 'kiosk'])
            ->where('employee_id', $employee->id)
            ->where('source', AttendanceLog::SOURCE_QR)
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (AttendanceLog $log) => $this->presentForEmployee($log))
            ->all();
    }

    private function presentForEmployee(AttendanceLog $log): array
    {
        return [
            'id' => $log->id,
            'timestamp' => $log->timestamp?->format('Y-m-d H:i:s'),
            'status_sync' => $log->status_sync,
            'outlet_name' => $log->outlet?->name,
            'kiosk_name' => $log->kiosk?->name,
            'distance_meters' => $log->distance_meters,
            'review_note' => $log->review_note,
        ];
    }
}
