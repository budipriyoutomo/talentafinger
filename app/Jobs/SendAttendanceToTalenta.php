<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;
use App\Models\AttendanceLog;
use App\Services\MekariTalentaService;

class SendAttendanceToTalenta implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct(
        private string $logId,
    ) {
        $this->onQueue('attendance');
    }

    public function backoff(): array
    {
        return [60, 120, 180];
    }

    /**
     * FR-09: rate limit outgoing calls to Mekari API.
     * If the limit is hit, the job is released back to the queue.
     */
    public function middleware(): array
    {
        return [(new RateLimited('mekari-api'))->dontRelease()];
    }

    public function handle(): void
    {
        $log = AttendanceLog::find($this->logId);

        if (!$log) {
            return;
        }

        $talentaService = new MekariTalentaService();

        // Import Fingerprint mengidentifikasi karyawan via badgeno (= biometric_id_lokal);
        // Talenta yang mencocokkan ke karyawan, jadi tidak perlu lookup talenta_employee_id.
        $csv = $talentaService->buildFingerprintCsv([
            [$log->biometric_id_lokal, $log->timestamp],
        ]);

        $response = $talentaService->importFingerprint($csv);

        if ($response->successful()) {
            $log->update(['status_sync' => 'sent', 'error_message' => null]);
        } else {
            throw new \Exception("Talenta menolak request (HTTP {$response->status()}): " . $response->body());
        }
    }

    public function failed(Throwable $exception): void
    {
        $log = AttendanceLog::find($this->logId);

        if ($log) {
            $log->update([
                'status_sync' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);
        }
    }
}
