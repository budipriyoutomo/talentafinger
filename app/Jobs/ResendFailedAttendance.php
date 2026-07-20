<?php

namespace App\Jobs;

use App\Models\AttendanceResendJob;
use App\Models\User;
use App\Services\AttendanceSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Kirim ulang log absensi berstatus 'failed' di background.
 *
 * Dulu ini dikerjakan langsung di dalam request web: semua log ditarik ke memori,
 * dijadikan satu CSV, dikirim dalam satu panggilan HTTP. Dengan log produksi yang
 * menumpuk, itu berakhir di timeout PHP — dan karena status baru ditulis setelah
 * response diterima, timeout berarti seluruh kerja hilang tanpa jejak. Di sini
 * pengiriman dipecah per chunk dan progresnya ditulis ke baris job.
 */
class ResendFailedAttendance implements ShouldQueue
{
    use Queueable;

    /**
     * Sengaja tidak di-retry. Job ini mengubah data (status log) dan sudah tahan
     * gagal sebagian per chunk; mengulang dari awal hanya akan mengirim ulang
     * log yang tadi sudah berhasil.
     */
    public $tries = 1;

    public $timeout = 3600;

    public function __construct(
        private string $jobId,
    ) {
        $this->onQueue('attendance');
    }

    public function handle(AttendanceSyncService $sync): void
    {
        $job = AttendanceResendJob::find($this->jobId);
        if (! $job) {
            return;
        }

        $job->update(['status' => 'processing']);

        // Batasan outlet ikut pemilik job, bukan konteks sistem — worker tak boleh
        // jadi celah yang mengirim log di luar wewenang orang yang menekan tombol.
        $user = $job->user_id ? User::find($job->user_id) : null;

        $result = $sync->resendFailedChunked(
            filters: $job->filters ?? [],
            ids: $job->selected_ids ?? [],
            user: $user,
            onProgress: function (int $done, int $total) use ($job) {
                $job->update(['progress_done' => $done, 'progress_total' => $total]);
            },
        );

        $job->update([
            'status' => 'done',
            'progress_done' => $result['total'],
            'progress_total' => $result['total'],
            'summary' => [
                'total' => $result['total'],
                'sent' => $result['sent'],
                'failed' => $result['failed'],
                'message' => $result['message'],
            ],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        AttendanceResendJob::where('id', $this->jobId)->update([
            'status' => 'failed',
            'error' => $exception->getMessage(),
        ]);
    }
}
