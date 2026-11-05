<?php

namespace Tests\Feature;

use App\Jobs\ProbeMachinesTcp;
use App\Models\Machine;
use App\Models\Setting;
use App\Services\ZkSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProbeMachineTcpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();
        Cache::forget('machine.tcp_probe.last_run');
    }

    private function enableProbe(int $interval = 5): void
    {
        Setting::updateOrCreate(
            ['key' => 'machine.tcp_probe'],
            ['value' => '1', 'group' => 'machine', 'type' => 'boolean', 'label' => 'Probe TCP'],
        );
        Setting::updateOrCreate(
            ['key' => 'machine.tcp_probe_interval'],
            ['value' => (string) $interval, 'group' => 'machine', 'type' => 'number', 'label' => 'Interval'],
        );
        Setting::flushCache();
    }

    private function machine(string $sn, ?string $ip = '10.0.0.1', bool $active = true): Machine
    {
        return Machine::create([
            'serial_number' => $sn,
            'name' => $sn,
            'is_active' => $active,
            'ip_address' => $ip,
        ]);
    }

    public function test_dispatches_job_when_enabled_and_interval_elapsed(): void
    {
        Queue::fake();
        $this->enableProbe();

        $this->artisan('machine:probe-tcp')->assertSuccessful();

        Queue::assertPushed(ProbeMachinesTcp::class, 1);
        $this->assertNotNull(Cache::get('machine.tcp_probe.last_run'));
    }

    public function test_does_not_dispatch_when_disabled(): void
    {
        Queue::fake();

        $this->artisan('machine:probe-tcp')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_does_not_dispatch_before_interval_elapsed(): void
    {
        Queue::fake();
        $this->enableProbe(5);
        Cache::put('machine.tcp_probe.last_run', now()->subMinutes(2)->toIso8601String());

        $this->artisan('machine:probe-tcp')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_force_ignores_interval_and_setting(): void
    {
        Queue::fake();
        Cache::put('machine.tcp_probe.last_run', now()->toIso8601String());

        $this->artisan('machine:probe-tcp', ['--force' => true])->assertSuccessful();

        Queue::assertPushed(ProbeMachinesTcp::class, 1);
    }

    public function test_command_never_pings_in_scheduler_process(): void
    {
        Queue::fake();
        $this->enableProbe();
        $this->machine('SN1');

        $zk = Mockery::mock(ZkSyncService::class);
        $zk->shouldNotReceive('ping');
        $this->app->instance(ZkSyncService::class, $zk);

        $this->artisan('machine:probe-tcp')->assertSuccessful();
    }

    public function test_job_updates_tcp_columns(): void
    {
        $up = $this->machine('UP', '10.0.0.1');
        $down = $this->machine('DOWN', '10.0.0.2');
        $inactive = $this->machine('OFF', '10.0.0.3', active: false);
        $noIp = $this->machine('NOIP', null);

        $zk = Mockery::mock(ZkSyncService::class);
        $zk->shouldReceive('ping')->twice()->andReturnUsing(fn (Machine $m) => $m->id === $up->id
            ? ['ok' => true, 'latency_ms' => 12]
            : ['ok' => false, 'error' => 'timed out']);

        $res = (new ProbeMachinesTcp)->handle($zk);

        $this->assertSame(['ok' => 1, 'down' => 1, 'skipped' => 0], $res);

        $up->refresh();
        $this->assertTrue($up->tcp_online);
        $this->assertSame(12, $up->tcp_latency_ms);
        $this->assertNull($up->tcp_error);
        $this->assertNotNull($up->tcp_checked_at);

        $down->refresh();
        $this->assertFalse($down->tcp_online);
        $this->assertNull($down->tcp_latency_ms);
        $this->assertSame('timed out', $down->tcp_error);

        $this->assertNull($inactive->refresh()->tcp_checked_at);
        $this->assertNull($noIp->refresh()->tcp_checked_at);
    }

    public function test_job_probes_least_recently_checked_first(): void
    {
        $recent = $this->machine('RECENT');
        $recent->update(['tcp_checked_at' => now()->subMinute()]);
        $old = $this->machine('OLD');
        $old->update(['tcp_checked_at' => now()->subHour()]);
        $never = $this->machine('NEVER');

        $order = [];
        $zk = Mockery::mock(ZkSyncService::class);
        $zk->shouldReceive('ping')->andReturnUsing(function (Machine $m) use (&$order) {
            $order[] = $m->serial_number;

            return ['ok' => true, 'latency_ms' => 1];
        });

        (new ProbeMachinesTcp)->handle($zk);

        $this->assertSame(['NEVER', 'OLD', 'RECENT'], $order);
    }

    public function test_job_timeout_stays_below_worker_timeout(): void
    {
        // Worker queue: --timeout=90, retry_after=90 (docker-compose.yml, config/queue.php).
        $job = new ProbeMachinesTcp;

        $this->assertLessThan(90, $job->timeout);
        $this->assertLessThan($job->timeout, ProbeMachinesTcp::TIME_BUDGET_SECONDS);
        $this->assertSame(1, $job->tries);
    }

    public function test_sync_option_probes_in_process(): void
    {
        $machine = $this->machine('SN1');

        $zk = Mockery::mock(ZkSyncService::class);
        $zk->shouldReceive('ping')->once()->andReturn(['ok' => true, 'latency_ms' => 7]);
        $this->app->instance(ZkSyncService::class, $zk);

        $this->artisan('machine:probe-tcp', ['--force' => true, '--sync' => true])
            ->expectsOutputToContain('1 ready')
            ->assertSuccessful();

        $this->assertTrue($machine->refresh()->tcp_online);
    }
}
