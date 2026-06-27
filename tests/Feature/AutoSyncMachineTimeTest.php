<?php

namespace Tests\Feature;

use App\Models\DeviceCommand;
use App\Models\Machine;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoSyncMachineTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();
    }

    private function enableAutoSync(): void
    {
        Setting::updateOrCreate(
            ['key' => 'machine.auto_sync_time'],
            ['value' => '1', 'group' => 'machine', 'type' => 'boolean', 'label' => 'Auto Sync Time'],
        );
        Setting::flushCache();
    }

    private function onlineMachine(): Machine
    {
        return Machine::create([
            'serial_number' => 'SN1',
            'name' => 'M1',
            'is_active' => true,
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
    }

    public function test_does_nothing_when_disabled(): void
    {
        $this->onlineMachine();

        // Tanpa setting (default) auto_sync_time nonaktif.
        $this->artisan('machine:auto-sync-time')->assertSuccessful();

        $this->assertSame(0, DeviceCommand::count());
    }

    public function test_queues_sync_time_for_online_active_machine(): void
    {
        $machine = $this->onlineMachine();
        $this->enableAutoSync();

        $this->artisan('machine:auto-sync-time')->assertSuccessful();

        $this->assertSame(1, DeviceCommand::where('machine_id', $machine->id)
            ->where('type', 'sync_time')
            ->where('status', 'pending')
            ->count());
    }

    public function test_does_not_pile_up_when_pending_exists(): void
    {
        $machine = $this->onlineMachine();
        $this->enableAutoSync();

        // Siklus pertama (force agar abaikan interval) mengantrekan 1 perintah.
        $this->artisan('machine:auto-sync-time --force')->assertSuccessful();
        // Siklus kedua harus dilewati karena masih ada sync_time pending.
        $this->artisan('machine:auto-sync-time --force')->assertSuccessful();

        $this->assertSame(1, DeviceCommand::where('machine_id', $machine->id)->count());
    }

    public function test_skips_offline_machine(): void
    {
        Machine::create([
            'serial_number' => 'SN2',
            'name' => 'M2',
            'is_active' => true,
            'status' => 'offline',
            'last_seen_at' => now()->subHours(2),
        ]);
        $this->enableAutoSync();

        $this->artisan('machine:auto-sync-time')->assertSuccessful();

        $this->assertSame(0, DeviceCommand::count());
    }
}
