<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Services\AttendanceSyncService;
use Illuminate\Http\Request;

/**
 * Log absensi mengikuti outlet MESIN tempat ia terekam. Pengiriman massal
 * ("kirim semua pending") juga dibatasi ke outlet wewenang user — lihat
 * AttendanceSyncService::sendPending().
 */
class AttendanceController extends Controller
{
    public function __construct(
        private AttendanceSyncService $sync,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', AttendanceLog::class);

        $query = AttendanceLog::visibleTo($request->user());

        if ($request->query('status')) {
            $query->where('status_sync', $request->query('status'));
        }

        if ($request->query('machine_id')) {
            $query->where('machine_id', $request->query('machine_id'));
        }

        if ($request->query('search')) {
            $search = $request->query('search');
            $query->where('biometric_id_lokal', 'like', "%$search%");
        }

        return $query->orderBy('created_at', 'desc')
            ->limit(500)
            ->get();
    }

    /**
     * Kirim satu attendance log ke Talenta (dipicu via tombol — pengiriman MANUAL).
     */
    public function send(Request $request, string $id)
    {
        $log = AttendanceLog::with('machine')->find($id);

        if (!$log) {
            return response()->json(['success' => false, 'message' => 'Log tidak ditemukan'], 404);
        }

        $this->authorize('send', $log);

        if ($log->status_sync === 'sent') {
            return response()->json(['success' => false, 'message' => 'Data sudah pernah terkirim'], 422);
        }

        $result = $this->sync->sendLogs(collect([$log]));

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'log' => $log->fresh(),
        ], $result['success'] ? 200 : 422);
    }

    /**
     * Kirim semua log 'pending' (dan opsional 'failed') secara batch — MANUAL.
     */
    public function sendPending(Request $request)
    {
        $this->authorizePermission($request, 'attendance.send');

        $result = $this->sync->sendPending($request->boolean('include_failed'), $request->user());

        return response()->json($result);
    }

    /**
     * Kirim ulang log berstatus 'failed' secara batch — MANUAL.
     * Tanpa 'ids' = semua log gagal; dengan 'ids' = hanya baris yang dicentang user.
     */
    public function sendFailed(Request $request)
    {
        $this->authorizePermission($request, 'attendance.send');

        $data = $request->validate([
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['uuid'],
        ]);

        // ids yang menunjuk log di luar scope tersaring diam-diam oleh visibleTo()
        // di dalam service, jadi tak ada log orang lain yang ikut terkirim.
        return response()->json($this->sync->sendFailed($data['ids'] ?? [], $request->user()));
    }
}
