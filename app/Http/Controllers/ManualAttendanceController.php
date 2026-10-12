<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
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
    /** Jeda minimal antar absen foto satu karyawan — cegah klik/kirim ganda. */
    public const COOLDOWN_MINUTES = 5;

    /** Disk PRIVAT: foto hanya bisa diambil lewat route photo() yang dijaga policy. */
    private const PHOTO_DISK = 'local';

    /** Halaman /absen (mobile-first, tanpa layout admin). */
    public function show(Request $request)
    {
        $this->authorizePermission($request, 'attendance.manual.create');

        $user = $request->user();
        $employee = $user->employee()->with('outlets.brand')->first();

        return Inertia::render('ManualCheckIn', [
            'employee' => $employee ? [
                'name' => $employee->name,
                'biometric_id' => $employee->biometric_id,
            ] : null,
            'blocked_reason' => $this->blockedReason($user, $employee),
            'radius_meters' => $this->radius(),
            'outlets' => $employee?->outlets->sortBy('name')->map(fn (Outlet $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'brand_name' => $o->brand?->name,
                'has_coordinates' => $o->hasCoordinates(),
            ])->values() ?? [],
            'recent' => $employee ? $this->recentLogs($employee) : [],
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

        $data = $request->validate([
            'photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:8192',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
        ], [
            'photo.required' => 'Foto wajib diambil.',
            'photo.image' => 'File harus berupa foto.',
            'photo.max' => 'Ukuran foto maksimal 8MB.',
            'latitude.required' => 'Lokasi tidak terbaca. Izinkan akses lokasi di browser.',
            'longitude.required' => 'Lokasi tidak terbaca. Izinkan akses lokasi di browser.',
        ]);

        $recent = AttendanceLog::where('employee_id', $employee->id)
            ->where('source', AttendanceLog::SOURCE_MANUAL_PHOTO)
            ->where('status_sync', '!=', 'rejected')
            ->where('created_at', '>=', now()->subMinutes(self::COOLDOWN_MINUTES))
            ->exists();

        if ($recent) {
            return response()->json([
                'message' => 'Anda baru saja absen. Tunggu ' . self::COOLDOWN_MINUTES . ' menit sebelum absen lagi.',
            ], 429);
        }

        // Geofence: cocokkan ke outlet TERDEKAT milik karyawan yang punya koordinat.
        $lat = (float) $data['latitude'];
        $lng = (float) $data['longitude'];
        $candidates = $employee->outlets->filter(fn (Outlet $o) => $o->hasCoordinates());

        if ($candidates->isEmpty()) {
            return response()->json([
                'message' => 'Outlet Anda belum punya titik koordinat. Hubungi admin.',
            ], 422);
        }

        $nearest = $candidates
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

        $path = $request->file('photo')->store('attendance-photos/' . now()->format('Y/m'), self::PHOTO_DISK);

        // Waktu disimpan dalam jam LOKAL perangkat (sama seperti log mesin), karena
        // itulah yang dikirim apa adanya ke Talenta.
        $tz = Setting::value('adms.device_timezone', 'Asia/Jakarta');

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

        $disk = Storage::disk(self::PHOTO_DISK);
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

        if ($log->status_sync !== 'pending_review') {
            return response()->json(['message' => 'Absen ini sudah direview sebelumnya.'], 422);
        }

        $log->update([
            // Disetujui -> 'pending': ikut auto-send / "Kirim Semua Pending" seperti log mesin.
            'status_sync' => $data['action'] === 'approve' ? 'pending' : 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['note'] ?? null,
        ]);

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
            default => null,
        };
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
