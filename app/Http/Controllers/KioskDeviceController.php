<?php

namespace App\Http\Controllers;

use App\Models\AttendanceKiosk;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\QrTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Endpoint PUBLIK untuk tablet kiosk di outlet (tanpa login user).
 *
 * Tablet diautentikasi dengan token device di header X-Kiosk-Token, didapat
 * sekali lewat kode aktivasi dari admin. Karena itu kiosk/* dikecualikan dari
 * CSRF (bootstrap/app.php): sesi browser tablet bisa kedaluwarsa kapan saja
 * tanpa memutus layar QR.
 */
class KioskDeviceController extends Controller
{
    /** last_seen_at hanya ditulis ulang paling sering sekali per rentang ini. */
    private const SEEN_THROTTLE_SECONDS = 60;

    /** Jumlah absen terbaru yang ditampilkan di layar kiosk. */
    private const RECENT_LIMIT = 5;

    public function __construct(private QrTokenService $tokens)
    {
    }

    /** GET /kiosk — layar tablet (aktivasi / QR). */
    public function show()
    {
        return Inertia::render('Kiosk');
    }

    /** POST /kiosk/activate — tukar kode aktivasi dengan token device. */
    public function activate(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:32']);

        $kiosk = AttendanceKiosk::findByActivationCode($data['code']);
        if ($kiosk === null) {
            return response()->json(['message' => 'Kode salah atau kedaluwarsa.'], 422);
        }

        $token = $kiosk->activate();
        $kiosk->load('outlet');

        // Tanpa aktor (tablet tak login); IP & user agent tetap tercatat.
        AuditLog::record('kiosk.activated', $kiosk);

        return response()->json([
            'token' => $token,
            'kiosk' => $this->presentKiosk($kiosk),
        ]);
    }

    /** GET /kiosk/state — QR terkini + feed absen. Dipolling tablet tiap pergantian QR. */
    public function state(Request $request)
    {
        $kiosk = AttendanceKiosk::findByDeviceToken($request->header('X-Kiosk-Token'));
        if ($kiosk === null) {
            return response()->json(['message' => 'Tablet belum diaktivasi atau sudah direset.'], 401);
        }

        $this->touch($kiosk, $request);
        $kiosk->load('outlet');

        $enabled = (bool) Setting::value('attendance.qr_enabled', false);
        $tz = Setting::value('adms.device_timezone', 'Asia/Jakarta');

        return response()->json([
            'kiosk' => $this->presentKiosk($kiosk),
            'enabled' => $enabled,
            'qr' => $enabled ? $this->tokens->urlFor($kiosk) : null,
            'rotate_seconds' => $this->tokens->rotateSeconds(),
            'server_time' => now($tz)->format('Y-m-d H:i:s'),
            'recent' => $this->recent($kiosk, $tz),
        ]);
    }

    private function touch(AttendanceKiosk $kiosk, Request $request): void
    {
        $stale = $kiosk->last_seen_at === null
            || $kiosk->last_seen_at->lt(now()->subSeconds(self::SEEN_THROTTLE_SECONDS));

        if ($stale || $kiosk->last_seen_ip !== $request->ip()) {
            $kiosk->forceFill([
                'last_seen_at' => now(),
                'last_seen_ip' => $request->ip(),
            ])->saveQuietly();
        }
    }

    /** Absen QR hari ini di kiosk ini. Nama depan saja: layar ini dilihat publik. */
    private function recent(AttendanceKiosk $kiosk, string $tz): array
    {
        return AttendanceLog::with('employee:id,name')
            ->where('kiosk_id', $kiosk->id)
            ->where('source', AttendanceLog::SOURCE_QR)
            ->where('status_sync', '!=', 'rejected')
            ->whereDate('timestamp', now($tz)->toDateString())
            ->latest('timestamp')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (AttendanceLog $log) => [
                'name' => Str::before(trim((string) $log->employee?->name), ' ') ?: 'Karyawan',
                'time' => $log->timestamp?->format('H:i'),
                'status' => $log->status_sync,
            ])
            ->all();
    }

    private function presentKiosk(AttendanceKiosk $kiosk): array
    {
        return [
            'name' => $kiosk->name,
            'outlet_name' => $kiosk->outlet?->name,
        ];
    }
}
