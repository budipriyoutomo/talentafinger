<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Services\AttendanceSyncService;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        private AttendanceSyncService $sync,
    ) {}

    /**
     * Kirim satu attendance log ke Talenta (dipicu via tombol — pengiriman MANUAL).
     */
    public function send(string $id)
    {
        $log = AttendanceLog::find($id);

        if (!$log) {
            return response()->json(['success' => false, 'message' => 'Log tidak ditemukan'], 404);
        }

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
        $result = $this->sync->sendPending($request->boolean('include_failed'));

        return response()->json($result);
    }
}
