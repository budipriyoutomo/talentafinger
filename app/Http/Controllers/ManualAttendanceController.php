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

    /** Batas ukuran foto dari sisi aplikasi (KB); batas PHP bisa lebih kecil. */
    private const MAX_PHOTO_KB = 8192;

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
            'max_photo_kb' => $this->maxPhotoKb(),
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

        // Upload yang ditolak PHP (melebihi upload_max_filesize) tetap muncul
        // sebagai file tak valid; tanpa cek ini validasi 'required' memberi pesan
        // menyesatkan "Foto wajib diambil" padahal fotonya ada, cuma kebesaran.
        $upload = $request->file('photo');
        if ($upload && ! $upload->isValid()) {
            return response()->json([
                'message' => 'Foto terlalu besar untuk diunggah (maks ' . $this->maxPhotoMbLabel() . '). Ambil ulang foto.',
            ], 422);
        }

        $maxKb = $this->maxPhotoKb();
        $data = $request->validate([
            'photo' => "required|image|mimes:jpeg,jpg,png,webp|max:{$maxKb}",
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
        ], [
            'photo.required' => 'Foto wajib diambil.',
            'photo.image' => 'File harus berupa foto.',
            'photo.max' => 'Ukuran foto maksimal ' . $this->maxPhotoMbLabel() . '.',
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

        $path = $request->file('photo')->store('attendance-photos/' . now()->format('Y/m'), self::PHOTO_DISK);

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
            Storage::disk(self::PHOTO_DISK)->delete($path);
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

    /**
     * Ukuran foto maksimal yang BENAR-BENAR bisa diterima (KB): yang terkecil
     * dari batas aplikasi dan batas upload PHP (upload_max_filesize/post_max_size).
     * Dikirim juga ke halaman supaya browser bisa menolak lebih awal.
     */
    private function maxPhotoKb(): int
    {
        $limits = array_filter([
            self::MAX_PHOTO_KB,
            $this->iniBytes('upload_max_filesize') / 1024,
            $this->iniBytes('post_max_size') / 1024,
        ]);

        return (int) floor(min($limits));
    }

    private function maxPhotoMbLabel(): string
    {
        return rtrim(rtrim(number_format($this->maxPhotoKb() / 1024, 1), '0'), '.') . 'MB';
    }

    /** Nilai ini PHP seperti "2M" -> byte (0 = tak terbatas). */
    private function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
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
