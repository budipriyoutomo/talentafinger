<?php

namespace App\Http\Controllers;

use App\Jobs\ResendFailedAttendance;
use App\Models\AttendanceLog;
use App\Models\AttendanceResendJob;
use App\Services\AttendanceSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
     *
     * Log yang sudah 'sent' hanya ikut terkirim bila pemanggil menyertakan
     * force=true — itulah tombol "Kirim Ulang" di baris tersebut. Tanpa flag itu
     * permintaan ditolak, supaya klik ganda pada tombol "Kirim" biasa tidak
     * pernah tanpa sengaja mengirim data yang sama dua kali.
     */
    public function send(Request $request, string $id)
    {
        $log = AttendanceLog::with('machine')->find($id);

        if (!$log) {
            return response()->json(['success' => false, 'message' => 'Log tidak ditemukan'], 404);
        }

        $this->authorize('send', $log);

        if ($log->status_sync === 'sent' && ! $request->boolean('force')) {
            return response()->json([
                'success' => false,
                'message' => 'Data sudah pernah terkirim. Pakai tombol "Kirim Ulang" bila memang ingin mengirimnya lagi.',
            ], 422);
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
     * Kirim ulang log ke Talenta — MANUAL, dikerjakan di BACKGROUND.
     *
     * 'status' menentukan kumpulan yang dikirim ulang: 'failed' (bawaan) atau
     * 'sent' untuk data yang SUDAH pernah terkirim tapi perlu dikirim lagi,
     * mis. karena hilang/berubah di sisi Talenta. Status lain ditolak validasi —
     * lihat AttendanceLog::RESENDABLE_STATUSES.
     *
     * Tanpa 'ids' = semua log berstatus itu YANG COCOK FILTER AKTIF; dengan 'ids'
     * = hanya baris yang dicentang user. Filter ikut dikirim dari halaman dan
     * dibaca lewat AttendanceLog::filtersFromRequest(), fungsi yang sama yang
     * dipakai tabelnya — jadi "semua gagal" berarti persis yang tampil di layar
     * setelah difilter, bukan seluruh isi database seperti perilaku lama.
     *
     * Mengembalikan 202 + job_id; progresnya dipolling lewat resendJob().
     */
    public function resend(Request $request)
    {
        $this->authorizePermission($request, 'attendance.send');

        $data = $request->validate([
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['uuid'],
            'status' => ['sometimes', 'in:' . implode(',', AttendanceLog::RESENDABLE_STATUSES)],
        ]);

        $user = $request->user();
        $ids = $data['ids'] ?? [];
        $target = $data['status'] ?? 'failed';

        // Status tujuan ditulis ke dalam filters, bukan disimpan terpisah: dengan
        // begitu "yang difilter tabel" dan "yang dikirim ulang" tetap satu nilai,
        // dan job background bisa membacanya kembali tanpa kolom tambahan.
        $filters = AttendanceLog::filtersFromRequest($request);
        $filters['status'] = $target;

        // Hitung dulu supaya user tahu berapa yang akan diproses, dan supaya
        // "tidak ada apa-apa untuk dikirim" tak perlu lewat queue sama sekali.
        // ids di luar scope tersaring diam-diam oleh visibleTo(), jadi menebak id
        // orang lain tak mengirim apa pun.
        $total = AttendanceLog::visibleTo($user)
            ->applyFilters($filters)
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->count();

        $label = $target === 'sent' ? 'terkirim' : 'gagal';

        if ($total === 0) {
            return response()->json([
                'ok' => false,
                'message' => "Tidak ada data {$label} untuk dikirim ulang.",
            ], 422);
        }

        $job = AttendanceResendJob::create([
            'user_id' => $user?->id,
            'filters' => $filters,
            'selected_ids' => $ids ?: null,
            'status' => 'queued',
            'progress_total' => $total,
            'progress_done' => 0,
        ]);

        ResendFailedAttendance::dispatch($job->id);

        return response()->json([
            'ok' => true,
            'job_id' => $job->id,
            'total' => $total,
            'message' => "Kirim ulang {$total} log {$label} diproses di background.",
        ], 202);
    }

    /** Status kirim ulang (dipolling frontend). */
    public function resendJob(Request $request, string $id)
    {
        $this->authorizePermission($request, 'attendance.send');

        $job = Str::isUuid($id) ? AttendanceResendJob::find($id) : null;

        // Job milik orang lain diperlakukan sama dengan job yang tak ada: tak ada
        // gunanya membocorkan bahwa id-nya benar tapi bukan punyamu.
        if (! $job || ($job->user_id && $job->user_id !== $request->user()?->id)) {
            return response()->json(['ok' => false, 'error' => 'Job tidak ditemukan.'], 404);
        }

        return response()->json([
            'ok' => true,
            'job_id' => $job->id,
            'status' => $job->status,
            'progress_total' => $job->progress_total,
            'progress_done' => $job->progress_done,
            'summary' => $job->summary,
            'error' => $job->error,
        ]);
    }
}
