<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use App\Models\Machine;
use App\Models\AttendanceLog;
use App\Jobs\SendAttendanceToTalenta;

class AdmsIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_payload_returns_ok_and_creates_log(): void
    {
        Queue::fake();

        $machine = Machine::create([
            'serial_number' => 'TEST001',
            'name' => 'Test Machine',
            'location' => 'Test Location',
            'status' => 'offline',
        ]);

        $payload = "ATTLOG\tSN=TEST001\t2026-06-15 08:03:22\t1001\t0\t1\t0";

        $response = $this->call('POST', '/iclock/cdata', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $payload);

        $response->assertStatus(200);
        $response->assertSee('OK');

        $this->assertDatabaseHas('attendance_logs', [
            'machine_id' => $machine->id,
            'biometric_id_lokal' => '1001',
            'timestamp' => '2026-06-15 08:03:22',
            'status_sync' => 'pending',
        ]);

        Queue::assertPushed(SendAttendanceToTalenta::class);
    }

    public function test_duplicate_payload_marked_as_duplicate(): void
    {
        Queue::fake();

        $machine = Machine::create([
            'serial_number' => 'TEST001',
            'name' => 'Test Machine',
            'location' => 'Test Location',
            'status' => 'offline',
        ]);

        $payload = "ATTLOG\tSN=TEST001\t2026-06-15 08:03:22\t1001\t0\t1\t0";

        // First post
        $this->call('POST', '/iclock/cdata', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $payload);

        // Second post - same data
        $response = $this->call('POST', '/iclock/cdata', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $payload);

        $response->assertStatus(200);
        $response->assertSee('OK');

        $duplicates = AttendanceLog::where('status_sync', 'duplicate')->count();
        $this->assertGreaterThan(0, $duplicates);
    }

    public function test_malformed_payload_returns_ok(): void
    {
        $machine = Machine::create([
            'serial_number' => 'TEST001',
            'name' => 'Test Machine',
            'location' => 'Test Location',
            'status' => 'offline',
        ]);

        $payload = "INVALID\tDATA";

        $response = $this->call('POST', '/iclock/cdata', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $payload);

        $response->assertStatus(200);
        $response->assertSee('OK');
    }

    public function test_unknown_machine_returns_ok(): void
    {
        $payload = "ATTLOG\tSN=UNKNOWN\t2026-06-15 08:03:22\t1001\t0\t1\t0";

        $response = $this->call('POST', '/iclock/cdata', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $payload);

        $response->assertStatus(200);
        $response->assertSee('OK');
    }
}
