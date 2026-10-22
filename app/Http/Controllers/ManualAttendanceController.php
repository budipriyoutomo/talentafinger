<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use App\Support\AttendancePhoto;
use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * Absen manual pakai foto + geolokasi, untuk karyawan yang sedang tidak berada
 * di dekat mesin fingerprint.
 *
 * Aktornya KARYAWAN sendiri (akun ber-role `employee` yang ditautkan ke
 * employees lewat users.employee_id), bukan admin/operator — karena itu
 * dipisah dari AttendanceController.
 *
 * Alur: karyawan kirim foto + lokasi -> lolos geofence -> log tersimpan sebagai
 * 'pending_review' -> supervisor Setujui (jadi 'pending', ikut auto-send ke
 * Talenta) atau Tolak ('rejected', tak pernah dikirim).
 */
class ManualAttendanceController extends Controller
{
    /**
     * Jeda minimal antar absen karyawan — cegah klik/kirim ganda. Berlaku lintas
     * sumber (foto + QR), lihat AttendanceLog::recentEmployeeSubmission().
     */
    public const COOLDOWN_MINUTES = 5;

    /** Halaman /absen (mobile-first, tanpa layout admin). */
    public function show(Request $request)
    {
        $this->authorizePermission($request, 'attendance.manual.create');

        $user = $request->user();
        $employee = $user->employee()->with('outlets')->first();

        return Inertia::render('ManualCheckIn', [
            'employee' => $employee ? [
                'name' => $employee->name,
                'biometric_id' => $employee->biometric_id,
            ] : null,
            'blocked_reason' => $this->blockedReason($user, $employee),
            'radius_meters' => $this->radius(),
            'max_photo_kb' => AttendancePhoto::maxKb(),
            'recent' => $employee ? $this->recentLogs($employee) : [],
            // Untuk petunjuk "scan QR di layar outlet" bila absen foto diblokir.
            'qr_available' => (bool) Setting::value('attendance.qr_enabled')
                && $user->hasPermission('attendance.qr.create'),
        ]);
    }

    /** POST /api/attendance/manual — kirim absen foto. */
    public function store(Request $request)
    {
        $this->authorizePermission($request, 'attendance.manual.create');

        $user = $request->user();
        $employee = $user->employee()->with('outlets')->first();

        if ($reason = $this->blockedReason($user, $employee)) {
            return response()->json(['message' => $reason], 403);
        }

        AttendancePhoto::assertUploadValid($request);
        $data = $request->validate(AttendancePhoto::rules(), AttendancePhoto::messages());

        if (AttendanceLog::recentEmployeeSubmission($employee->id, self::COOLDOWN_MINUTES)) {
            return response()->json([
                'message' => 'Anda baru saja absen. Tunggu ' . self::COOLDOWN_MINUTES . ' menit sebelum absen lagi.',
            ], 429);
        }

        // Geofence: cocokkan ke outlet TERDEKAT milik karyawan yang punya koordinat
        // yang masih aktif (minimal satu dijamin ada oleh blockedReason()).
        $lat = (float) $data['latitude'];
        $lng = (float) $data['longitude'];

        $nearest = $this->geofenceOutlets($employee)
            ->map(fn (Outlet $o) => [
                'outlet' => $o,
                'distance' => Geo::distanceMeters($lat, $lng, $o->latitude, $o->longitude),
            ])
            ->sortBy('distance')
            ->first();

        $distance = (int) round($nearest['distance']);
        $radius = $this->radius();

        if ($distance > $radius) {
            return response()->json([
                'message' => "Anda berada ±{$distance} m dari outlet terdekat ({$nearest['outlet']->name}). "
                    . "Absen foto hanya bisa dalam radius {$radius} m.",
                'distance_meters' => $distance,
                'radius_meters' => $radius,
            ], 422);
        }

        $path = AttendancePhoto::store($request->file('photo'));

        // Waktu disimpan dalam jam LOKAL perangkat (sama seperti log mesin), karena
        // itulah yang dikirim apa adanya ke Talenta.
        $tz = Setting::value('adms.device_timezone', 'Asia/Jakarta');

        try {
            $log = AttendanceLog::create([
                'source' => AttendanceLog::SOURCE_MANUAL_PHOTO,
                'employee_id' => $employee->id,
                'outlet_id' => $nearest['outlet']->id,
                'machine_id' => null,
                'biometric_id_lokal' => $employee->biometric_id,
                'timestamp' => now($tz)->format('Y-m-d H:i:s'),
                'status_sync' => 'pending_review',
                'photo_path' => $path,
                'latitude' => $lat,
                'longitude' => $lng,
                'accuracy_meters' => isset($data['accuracy']) ? (int) round($data['accuracy']) : null,
                'distance_meters' => $distance,
            ]);
        } catch (\Throwable $e) {
            // Jangan tinggalkan foto yatim di disk bila log gagal tersimpan.
            Storage::disk(AttendancePhoto::DISK)->delete($path);
            throw $e;
        }

        return response()->json([
            'message' => "Absen tercatat di {$nearest['outlet']->name} (±{$distance} m). Menunggu persetujuan supervisor.",
            'log' => $this->presentForEmployee($log->load('outlet')),
        ], 201);
    }

    /** GET /api/attendance-logs/{id}/photo — foto absen manual (disk privat). */
    public function photo(string $id)
    {
        $log = AttendanceLog::with('machine')->findOrFail($id);
        $this->authorize('viewPhoto', $log);

        $disk = Storage::disk(AttendancePhoto::DISK);
        abort_unless($log->photo_path && $disk->exists($log->photo_path), 404);

        return $disk->response($log->photo_path, null, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** POST /api/attendance-logs/{id}/review — supervisor Setujui / Tolak. */
    public function review(Request $request, string $id)
    {
        $log = AttendanceLog::findOrFail($id);
        $this->authorize('review', $log);

        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:500|required_if:action,reject',
        ], [
            'note.required_if' => 'Alasan penolakan wajib diisi.',
        ]);

        // Update bersyarat (atomik): bila dua supervisor me-review bersamaan, hanya
        // yang pertama menang — yang kedua tak menimpa keputusan yang sudah ada.
        $updated = AttendanceLog::whereKey($log->id)
            ->where('status_sync', 'pending_review')
            ->update([
                // Disetujui -> 'pending': ikut auto-send / "Kirim Semua Pending" seperti log mesin.
                'status_sync' => $data['action'] === 'approve' ? 'pending' : 'rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $data['note'] ?? null,
            ]);

        if ($updated === 0) {
            return response()->json(['message' => 'Absen ini sudah direview sebelumnya.'], 422);
        }

        AuditLog::record(
            $data['action'] === 'approve' ? 'attendance.manual_approved' : 'attendance.manual_rejected',
            ['type' => 'attendance_log', 'id' => $log->id, 'label' => $log->employee?->name],
            ['source' => $log->source, 'note' => $data['note'] ?? null],
        );

        return response()->json([
            'message' => $data['action'] === 'approve'
                ? 'Absen disetujui dan masuk antrean kirim ke Talenta.'
                : 'Absen ditolak.',
            'log' => $log->fresh(),
        ]);
    }

    /**
     * Alasan karyawan TIDAK boleh absen foto, atau null bila boleh. Ditampilkan
     * apa adanya ke karyawan, jadi ditulis sebagai petunjuk tindakan.
     */
    private function blockedReason(User $user, ?Employee $employee): ?string
    {
        return match (true) {
            ! Setting::value('attendance.manual_photo_enabled') => 'Absen foto sedang dinonaktifkan oleh admin.',
            $employee === null => 'Akun Anda belum ditautkan ke data karyawan. Hubungi admin.',
            ! $employee->is_active => 'Data karyawan Anda nonaktif. Hubungi admin.',
            ! $employee->allow_manual_attendance => 'Anda belum diizinkan absen pakai foto. Hubungi admin/manager outlet.',
            $employee->outlets->where('is_active', true)->isEmpty() => 'Anda belum ditautkan ke outlet aktif mana pun. Hubungi admin.',
            $this->geofenceOutlets($employee)->isEmpty() => 'Outlet Anda belum punya titik koordinat. Hubungi admin.',
            default => null,
        };
    }

    /** Outlet karyawan yang bisa dipakai geofence: aktif DAN punya koordinat. */
    private function geofenceOutlets(Employee $employee)
    {
        return $employee->outlets->filter(fn (Outlet $o) => $o->is_active && $o->hasCoordinates());
    }

    private function radius(): int
    {
        return max(10, (int) Setting::value('attendance.manual_photo_radius', 150));
    }

    private function recentLogs(Employee $employee): array
    {
        return AttendanceLog::with('outlet')
            ->where('employee_id', $employee->id)
            ->where('source', AttendanceLog::SOURCE_MANUAL_PHOTO)
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
            'distance_meters' => $log->distance_meters,
            'review_note' => $log->review_note,
            'photo_url' => "/api/attendance-logs/{$log->id}/photo",
        ];
    }
}
