<?php

namespace Tests\Feature;

use App\Jobs\ResendFailedAttendance;
use App\Models\AttendanceLog;
use App\Models\AttendanceResendJob;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Machine;
use App\Models\Outlet;
use App\Models\User;
use App\Services\AttendanceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Kirim Ulang Semua Gagal" dulu MENGABAIKAN filter panel: layar bisa menampilkan
 * 20 log satu outlet sementara tombolnya mengirim seluruh log gagal di database.
 * Test di kelas ini yang menjaga supaya perilaku itu tak diam-diam kembali.
 */
class ResendFailedAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $kopi;
    private Outlet $sate;
    private Machine $mesinKopi;
    private Machine $mesinSate;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $pt = Company::create(['name' => 'PT Maju']);
        $this->kopi = Outlet::create([
            'brand_id' => Brand::create(['company_id' => $pt->id, 'name' => 'Kopi'])->id,
            'name' => 'Kopi Sudirman',
        ]);
        $this->sate = Outlet::create([
            'brand_id' => Brand::create(['company_id' => $pt->id, 'name' => 'Sate'])->id,
            'name' => 'Sate Menteng',
        ]);

        $this->mesinKopi = Machine::create(['serial_number' => 'K1', 'name' => 'Mesin Kopi', 'outlet_id' => $this->kopi->id]);
        $this->mesinSate = Machine::create(['serial_number' => 'S1', 'name' => 'Mesin Sate', 'outlet_id' => $this->sate->id]);

        Employee::create([
            'name' => 'Budi', 'biometric_id' => '1001',
            'talenta_employee_id' => 'TAL-1', 'outlet_id' => $this->kopi->id,
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@adms.local',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
    }

    private function failedLogs(Machine $machine, int $count): void
    {
        foreach (range(1, $count) as $i) {
            AttendanceLog::create([
                'machine_id' => $machine->id,
                'biometric_id_lokal' => '1001',
                'timestamp' => now(),
                'status_sync' => 'failed',
            ]);
        }
    }

    public function test_kirim_ulang_hanya_memproses_log_yang_cocok_filter_outlet(): void
    {
        Queue::fake();
        $this->failedLogs($this->mesinKopi, 3);
        $this->failedLogs($this->mesinSate, 5);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/attendance-logs/send-failed', ['outlet_id' => $this->kopi->id]);

        // Inti perbaikan: 3 (outlet terfilter), bukan 8 (seluruh database).
        $response->assertStatus(202)->assertJson(['ok' => true, 'total' => 3]);

        $job = AttendanceResendJob::first();
        $this->assertSame($this->kopi->id, $job->filters['outlet_id']);
        Queue::assertPushed(ResendFailedAttendance::class);
    }

    public function test_tanpa_filter_memproses_semua_log_gagal(): void
    {
        Queue::fake();
        $this->failedLogs($this->mesinKopi, 3);
        $this->failedLogs($this->mesinSate, 5);

        $this->actingAs($this->admin)
            ->postJson('/api/attendance-logs/send-failed', [])
            ->assertStatus(202)
            ->assertJson(['total' => 8]);
    }

    public function test_centang_baris_mengalahkan_filter(): void
    {
        Queue::fake();
        $this->failedLogs($this->mesinKopi, 3);
        $ids = AttendanceLog::limit(2)->pluck('id')->all();

        $this->actingAs($this->admin)
            ->postJson('/api/attendance-logs/send-failed', ['ids' => $ids])
            ->assertStatus(202)
            ->assertJson(['total' => 2]);
    }

    public function test_tidak_ada_log_gagal_menolak_tanpa_membuat_job(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)
            ->postJson('/api/attendance-logs/send-failed', [])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame(0, AttendanceResendJob::count());
        Queue::assertNothingPushed();
    }

    public function test_job_hanya_mengubah_log_yang_cocok_filter(): void
    {
        Http::fake(['*' => Http::response(json_encode(['success' => true]), 200)]);
        $this->failedLogs($this->mesinKopi, 3);
        $this->failedLogs($this->mesinSate, 5);

        $job = AttendanceResendJob::create([
            'user_id' => $this->admin->id,
            'filters' => ['outlet_id' => $this->kopi->id],
            'status' => 'queued', 'progress_total' => 3, 'progress_done' => 0,
        ]);

        (new ResendFailedAttendance($job->id))->handle(app(AttendanceSyncService::class));

        $this->assertSame('done', $job->fresh()->status);
        $this->assertSame(0, AttendanceLog::where('machine_id', $this->mesinKopi->id)->where('status_sync', 'failed')->count());
        // Outlet lain tak boleh ikut terkirim.
        $this->assertSame(5, AttendanceLog::where('machine_id', $this->mesinSate->id)->where('status_sync', 'failed')->count());
    }

    public function test_pengiriman_dipecah_per_chunk(): void
    {
        Http::fake(['*' => Http::response(json_encode(['success' => true]), 200)]);
        $this->failedLogs($this->mesinKopi, AttendanceSyncService::CHUNK_SIZE + 10);

        $progress = [];
        $result = app(AttendanceSyncService::class)->resendFailedChunked(
            filters: [], ids: [], user: $this->admin,
            onProgress: function (int $done) use (&$progress) { $progress[] = $done; },
        );

        // 510 log dengan CHUNK_SIZE 500 = dua upload terpisah, bukan satu raksasa.
        Http::assertSentCount(2);
        $this->assertSame([500, 510], $progress);
        $this->assertSame(510, $result['sent']);
    }

    public function test_status_job_orang_lain_tidak_bisa_diintip(): void
    {
        $lain = User::create([
            'name' => 'Lain', 'email' => 'lain@adms.local',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        $job = AttendanceResendJob::create([
            'user_id' => $lain->id, 'filters' => [], 'status' => 'processing',
            'progress_total' => 10, 'progress_done' => 1,
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/attendance-logs/resend-jobs/{$job->id}")
            ->assertStatus(404);
    }
}
