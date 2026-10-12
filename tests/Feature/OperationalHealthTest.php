<?php

namespace Tests\Feature;

use App\Models\DeviceCommand;
use App\Models\Machine;
use App\Models\User;
use App\Services\DeviceCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Keandalan operasional: pengiriman ulang perintah mesin, statistik antrean
 * dashboard, healthcheck, dan retensi arsip Talenta.
 */
class OperationalHealthTest extends TestCase
{
    use RefreshDatabase;

    private function machine(): Machine
    {
        return Machine::create([
            'serial_number' => 'TEST001',
            'name' => 'Test Machine',
            'location' => 'Test Location',
            'is_active' => true,
        ]);
    }

    private function poll()
    {
        return $this->call('GET', '/iclock/getrequest?SN=TEST001');
    }

    // ===== Perintah mesin =====

    public function test_perintah_sent_tanpa_balasan_dikirim_ulang_setelah_timeout(): void
    {
        $machine = $this->machine();
        $cmd = DeviceCommand::create([
            'machine_id' => $machine->id,
            'type' => 'push_user',
            'command' => 'DATA UPDATE USERINFO PIN=1001',
            'status' => 'pending',
        ]);

        $this->poll()->assertSee("C:{$cmd->id}:", false);
        // Belum lewat batas waktu: jangan dikirim ganda.
        $this->poll()->assertDontSee("C:{$cmd->id}:", false);

        $this->travel(DeviceCommandService::RESEND_AFTER_MINUTES + 1)->minutes();
        $this->poll()->assertSee("C:{$cmd->id}:", false);

        $this->assertSame(2, $cmd->fresh()->attempts);
        $this->assertSame('sent', $cmd->fresh()->status);
    }

    public function test_perintah_expired_setelah_batas_percobaan(): void
    {
        $machine = $this->machine();
        $cmd = DeviceCommand::create([
            'machine_id' => $machine->id,
            'type' => 'push_user',
            'command' => 'DATA UPDATE USERINFO PIN=1001',
            'status' => 'pending',
        ]);

        foreach (range(1, DeviceCommandService::MAX_ATTEMPTS) as $i) {
            $this->poll()->assertSee("C:{$cmd->id}:", false);
            $this->travel(DeviceCommandService::RESEND_AFTER_MINUTES + 1)->minutes();
        }

        $this->poll()->assertDontSee("C:{$cmd->id}:", false);
        $this->assertSame('expired', $cmd->fresh()->status);
        $this->assertSame(DeviceCommandService::MAX_ATTEMPTS, $cmd->fresh()->attempts);
    }

    public function test_sync_time_expired_tak_memblokir_auto_sync_berikutnya(): void
    {
        $machine = $this->machine();
        DeviceCommand::create([
            'machine_id' => $machine->id,
            'type' => 'sync_time',
            'command' => 'SET OPTIONS DateTime=1',
            'status' => 'expired',
        ]);

        $this->assertNotNull(app(DeviceCommandService::class)->queueSyncTimeIfAbsent($machine));
    }

    public function test_sync_time_memakai_jam_saat_dikirim_bukan_saat_diantrekan(): void
    {
        $machine = $this->machine();
        $this->travelTo(now()->setDate(2026, 1, 1)->setTime(8, 0));
        $cmd = app(DeviceCommandService::class)->queueSyncTime($machine);
        $queuedCommand = $cmd->command;

        // Mesin baru polling 3 jam kemudian (mis. sempat offline).
        $this->travel(3)->hours();
        $this->poll();

        $this->assertNotSame($queuedCommand, $cmd->fresh()->command);
        $expected = app(DeviceCommandService::class)->encodeTime(now()->setTimezone('Asia/Jakarta'));
        $this->assertSame("SET OPTIONS DateTime={$expected}", $cmd->fresh()->command);
    }

    public function test_status_mesin_tidak_lagi_ditulis_saat_polling(): void
    {
        $machine = $this->machine();
        $machine->forceFill(['status' => 'offline'])->save();

        $this->poll();

        $this->assertSame('offline', $machine->fresh()->status);
        $this->assertNotNull($machine->fresh()->last_seen_at);
        $this->assertTrue($machine->fresh()->isOnline());
    }

    // ===== Dashboard & healthcheck =====

    public function test_dashboard_stats_menghitung_job_antrean_dan_gagal(): void
    {
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'admin@adms.local',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]));
        config(['queue.default' => 'database']);

        DB::table('jobs')->insert([
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()],
            ['queue' => 'attendance', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => time(), 'available_at' => time(), 'created_at' => time()],
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'f1', 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
        ]);

        $this->getJson('/api/dashboard-stats')
            ->assertOk()
            ->assertJson(['jobs_waiting' => 1, 'jobs_processing' => 1, 'jobs_failed' => 1]);
    }

    public function test_healthcheck_up_ok_saat_db_terjangkau(): void
    {
        $this->get('/up')->assertOk();
    }

    // ===== Retensi arsip Talenta =====

    public function test_prune_menghapus_arsip_lama_dan_menyimpan_yang_baru(): void
    {
        Storage::fake('local');
        Storage::put('talenta/lama.csv', 'x');
        Storage::put('talenta/baru.csv', 'x');
        touch(Storage::path('talenta/lama.csv'), now()->subDays(100)->getTimestamp());
        config(['mekari.file_retention_days' => 90]);

        $this->artisan('talenta:prune-files')->assertSuccessful();

        Storage::assertMissing('talenta/lama.csv');
        Storage::assertExists('talenta/baru.csv');
    }

    public function test_prune_nonaktif_bila_retensi_nol(): void
    {
        Storage::fake('local');
        Storage::put('talenta/lama.csv', 'x');
        touch(Storage::path('talenta/lama.csv'), now()->subDays(1000)->getTimestamp());
        config(['mekari.file_retention_days' => 0]);

        $this->artisan('talenta:prune-files')->assertSuccessful();

        Storage::assertExists('talenta/lama.csv');
    }
}
