<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Machine;
use App\Models\Setting;
use App\Services\AttendanceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendanceSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();
        Storage::fake('local');
        config([
            'mekari.base_url' => 'https://sandbox-api.mekari.test/v2/talenta/v2',
            'mekari.client_id' => 'cid',
            'mekari.client_secret' => 'secret',
        ]);
    }

    private function machine(): Machine
    {
        return Machine::create([
            'serial_number' => 'SN1',
            'name' => 'M1',
            'is_active' => true,
            'status' => 'offline',
        ]);
    }

    /** Log pending DENGAN karyawan ber-Biometric ID (badgeno resolvable). */
    private function pendingLog(): AttendanceLog
    {
        $machine = $this->machine();

        // PIN 1001 (biometric_id) -> talenta_employee_id TAL-99.
        Employee::create([
            'name' => 'Budi',
            'talenta_employee_id' => 'TAL-99',
            'biometric_id' => '1001',
            'is_active' => true,
        ]);

        return AttendanceLog::create([
            'machine_id' => $machine->id,
            'biometric_id_lokal' => '1001',
            'timestamp' => '2026-06-15 08:00:00',
            'status_sync' => 'pending',
            'payload_raw' => 'raw',
        ]);
    }

    public function test_marks_sent_when_talenta_accepts(): void
    {
        Http::fake(['*' => Http::response(['code' => 200, 'message' => 'Success'], 200)]);
        $log = $this->pendingLog();

        $result = app(AttendanceSyncService::class)->sendPending();

        $this->assertTrue($result['success']);
        $this->assertSame('sent', $log->fresh()->status_sync);
        $this->assertNull($log->fresh()->error_message);
    }

    public function test_marks_failed_on_http_error(): void
    {
        Http::fake(['*' => Http::response(['message' => 'input validation error'], 400)]);
        $log = $this->pendingLog();

        $result = app(AttendanceSyncService::class)->sendPending();

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $log->fresh()->status_sync);
        $this->assertNotNull($log->fresh()->error_message);
    }

    /**
     * REGRESI: Talenta membalas HTTP 200 tetapi body berisi `errors`
     * (mis. "setting absence not found"). Sebelum perbaikan, log keliru
     * ditandai `sent` padahal data tak masuk. Harus `failed`.
     */
    public function test_marks_failed_when_2xx_but_body_has_errors(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'input validation error',
            'errors' => ['setting absence not found for this company'],
            'request_id' => 'abc',
        ], 200)]);
        $log = $this->pendingLog();

        $result = app(AttendanceSyncService::class)->sendPending();

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $log->fresh()->status_sync);
    }

    public function test_marks_failed_on_network_exception(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
        $log = $this->pendingLog();

        $result = app(AttendanceSyncService::class)->sendPending();

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $log->fresh()->status_sync);
    }

    public function test_does_nothing_when_no_pending_logs(): void
    {
        Http::fake();

        $result = app(AttendanceSyncService::class)->sendPending();

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['sent']);
        Http::assertNothingSent();
    }

    public function test_sends_resolved_talenta_badge_not_raw_pin(): void
    {
        Http::fake(['*' => Http::response(['code' => 200], 200)]);
        $this->pendingLog(); // PIN 1001 -> talenta_employee_id TAL-99

        app(AttendanceSyncService::class)->sendPending();

        Http::assertSent(function ($request) {
            $body = (string) $request->body();
            // Multipart berisi CSV: badgeno = TAL-99 (hasil resolve), bukan PIN 1001.
            return str_contains($body, 'TAL-99') && ! preg_match('/(^|;)1001;/m', $body);
        });
    }

    public function test_log_without_matching_employee_is_failed_and_not_sent(): void
    {
        Http::fake();
        $machine = $this->machine();
        $log = AttendanceLog::create([
            'machine_id' => $machine->id,
            'biometric_id_lokal' => '9999', // tak ada karyawan ber-biometric_id ini
            'timestamp' => '2026-06-15 08:00:00',
            'status_sync' => 'pending',
            'payload_raw' => 'raw',
        ]);

        $result = app(AttendanceSyncService::class)->sendPending();

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $log->fresh()->status_sync);
        $this->assertStringContainsString('karyawan Talenta', $log->fresh()->error_message);
        Http::assertNothingSent();
    }
}
