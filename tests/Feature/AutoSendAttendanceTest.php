<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Machine;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoSendAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();
        config(['mekari.base_url' => 'https://sandbox-api.mekari.test/v2/talenta/v2']);
    }

    private function pendingLog(): AttendanceLog
    {
        $machine = Machine::create([
            'serial_number' => 'SN1',
            'name' => 'M1',
            'is_active' => true,
            'status' => 'offline',
        ]);

        return AttendanceLog::create([
            'machine_id' => $machine->id,
            'biometric_id_lokal' => '1001',
            'timestamp' => '2026-06-15 08:00:00',
            'status_sync' => 'pending',
            'payload_raw' => 'raw',
        ]);
    }

    public function test_does_nothing_when_auto_send_disabled(): void
    {
        Http::fake();
        $this->pendingLog();

        // Tanpa setting (default) auto_send nonaktif.
        $this->artisan('attendance:auto-send')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('pending', AttendanceLog::first()->status_sync);
    }

    public function test_force_sends_pending_logs(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $log = $this->pendingLog();

        $this->artisan('attendance:auto-send --force')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'import-fingerprint'));
        $this->assertSame('sent', $log->fresh()->status_sync);
    }
}
