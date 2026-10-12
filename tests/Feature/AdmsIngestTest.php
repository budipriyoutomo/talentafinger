<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Machine;
use App\Models\AttendanceLog;
use App\Models\DeviceCommand;

class AdmsIngestTest extends TestCase
{
    use RefreshDatabase;

    private function machine(): Machine
    {
        return Machine::create([
            'serial_number' => 'TEST001',
            'name' => 'Test Machine',
            'location' => 'Test Location',
            'is_active' => true,
            'status' => 'offline',
        ]);
    }

    private function ingest(string $sn, string $body)
    {
        // SN dikirim mesin via query string; body = PIN\tDateTime\tStatus\tVerify\tWorkCode.
        return $this->call('POST', "/iclock/cdata?SN={$sn}", [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    public function test_valid_payload_returns_ok_and_creates_log(): void
    {
        $machine = $this->machine();

        $response = $this->ingest('TEST001', "1001\t2026-06-15 08:03:22\t0\t1\t0");

        $response->assertStatus(200);
        $response->assertSee('OK');

        $this->assertDatabaseHas('attendance_logs', [
            'machine_id' => $machine->id,
            'biometric_id_lokal' => '1001',
            'timestamp' => '2026-06-15 08:03:22',
            'status_sync' => 'pending',
        ]);
    }

    public function test_kiriman_ulang_diabaikan_dan_log_asli_tetap_pending(): void
    {
        // Regresi: dulu kiriman ulang mengubah log asli 'pending' jadi 'duplicate',
        // sehingga absensinya tak pernah terkirim ke Talenta.
        $this->machine();
        $body = "1001\t2026-06-15 08:03:22\t0\t1\t0";

        $this->ingest('TEST001', $body);
        $response = $this->ingest('TEST001', $body);

        $response->assertStatus(200);
        $this->assertSame(1, AttendanceLog::count());
        $this->assertSame('pending', AttendanceLog::first()->status_sync);
    }

    public function test_kiriman_ulang_tidak_mengubah_log_yang_sudah_terkirim(): void
    {
        $this->machine();
        $body = "1001\t2026-06-15 08:03:22\t0\t1\t0";

        $this->ingest('TEST001', $body);
        AttendanceLog::query()->update(['status_sync' => 'sent']);
        $this->ingest('TEST001', $body);

        $this->assertSame(1, AttendanceLog::count());
        $this->assertSame('sent', AttendanceLog::first()->status_sync);
    }

    public function test_malformed_payload_returns_ok_without_log(): void
    {
        $this->machine();

        $response = $this->ingest('TEST001', 'INVALID');

        $response->assertStatus(200);
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_unknown_machine_returns_ok(): void
    {
        $response = $this->ingest('UNKNOWN', "1001\t2026-06-15 08:03:22\t0\t1\t0");

        $response->assertStatus(200);
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_inactive_machine_does_not_store_log(): void
    {
        Machine::create([
            'serial_number' => 'TEST002',
            'name' => 'Inactive',
            'is_active' => false,
            'status' => 'offline',
        ]);

        $response = $this->ingest('TEST002', "1001\t2026-06-15 08:03:22\t0\t1\t0");

        $response->assertStatus(200);
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_fingerprint_template_upload_is_stored_not_logged_as_attendance(): void
    {
        $machine = $this->machine();

        // table != ATTLOG -> body berisi baris template "FP PIN=..."
        $body = "FP PIN=1001\tFID=5\tSize=12\tValid=1\tTMP=QUJDREVG";
        $response = $this->call('POST', '/iclock/cdata?SN=TEST001&table=OPERLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);

        $response->assertStatus(200);
        $this->assertSame(0, AttendanceLog::count(), 'Upload template tidak boleh jadi log absensi');
        $this->assertDatabaseHas('biometric_templates', [
            'biometric_id' => '1001',
            'fid' => 5,
            'source_machine_id' => $machine->id,
        ]);
    }

    public function test_getrequest_delivers_pending_command_then_marks_sent(): void
    {
        $machine = $this->machine();
        $cmd = DeviceCommand::create([
            'machine_id' => $machine->id,
            'command' => 'SET OPTIONS DateTime=...',
            'status' => 'pending',
        ]);

        $response = $this->call('GET', "/iclock/getrequest?SN=TEST001");

        $response->assertStatus(200);
        $response->assertSee("C:{$cmd->id}:", false);
        $this->assertSame('sent', $cmd->fresh()->status);
        $this->assertNotNull($cmd->fresh()->sent_at);
    }

    public function test_devicecmd_marks_command_done(): void
    {
        $machine = $this->machine();
        $cmd = DeviceCommand::create([
            'machine_id' => $machine->id,
            'command' => 'SET OPTIONS DateTime=...',
            'status' => 'sent',
        ]);

        $response = $this->call('POST', '/iclock/devicecmd?SN=TEST001', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "ID={$cmd->id}&Return=0&CMD=OPTIONS");

        $response->assertStatus(200);
        $this->assertSame('done', $cmd->fresh()->status);
        $this->assertNotNull($cmd->fresh()->done_at);
    }

    public function test_devicecmd_tidak_bisa_menandai_perintah_mesin_lain(): void
    {
        $machine = $this->machine();
        Machine::create([
            'serial_number' => 'OTHER001',
            'name' => 'Mesin Lain',
            'location' => 'Lain',
            'is_active' => true,
            'status' => 'offline',
        ]);
        $cmd = DeviceCommand::create([
            'machine_id' => $machine->id,
            'command' => 'SET OPTIONS DateTime=...',
            'status' => 'sent',
        ]);

        $body = "ID={$cmd->id}&Return=0&CMD=OPTIONS";
        $this->call('POST', '/iclock/devicecmd?SN=OTHER001', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)->assertStatus(200);
        $this->call('POST', '/iclock/devicecmd', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)->assertStatus(200);

        $this->assertSame('sent', $cmd->fresh()->status);
        $this->assertNull($cmd->fresh()->done_at);
    }

    public function test_unique_index_menolak_log_kembar(): void
    {
        $machine = $this->machine();
        $data = [
            'machine_id' => $machine->id,
            'biometric_id_lokal' => '1001',
            'timestamp' => '2026-06-15 08:03:22',
            'status_sync' => 'pending',
        ];
        AttendanceLog::create($data);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        AttendanceLog::create($data);
    }
}
