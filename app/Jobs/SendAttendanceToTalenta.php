<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;
use App\Models\AttendanceLog;
use App\Services\EmployeeMappingService;
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

        $mappingService = new EmployeeMappingService();
        $talentaService = new MekariTalentaService();

        $talentaId = $mappingService->findTalentaId($log->machine_id, $log->biometric_id_lokal);

        if (!$talentaId) {
            $log->update([
                'status_sync' => 'failed',
                'error_message' => 'No mapping found for biometric ID',
            ]);
            return;
        }

        try {
            $success = $talentaService->sendAttendance($talentaId, $log->timestamp->toIso8601String());

            if ($success) {
                $log->update(['status_sync' => 'sent']);
            } else {
                throw new \Exception('HTTP request failed');
            }
        } catch (Throwable $e) {
            throw $e;
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
