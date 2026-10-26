<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Setting;
use App\Models\TalentaEmployeeSyncRun;
use App\Services\TalentaEmployeeSyncRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class TalentaEmployeeSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();
        Sleep::fake();
        config([
            'mekari.base_url' => 'https://sandbox-api.mekari.test/v2/talenta/v2',
            'mekari.client_id' => 'cid',
            'mekari.client_secret' => 'secret',
        ]);

        Http::fake(['*/employee*' => Http::response([
            'status' => 'success',
            'data' => [
                'employees' => [[
                    'user_id' => 1,
                    'personal' => ['first_name' => 'Ani', 'last_name' => '', 'barcode' => '600001', 'email' => 'ani@example.test'],
                    'employment' => ['employee_id' => 'PC-600001', 'status' => 'Active', 'resign_date' => '', 'branch_id' => '0', 'branch' => 'HO'],
                ]],
                'pagination' => ['current_page' => 1, 'last_page' => 1, 'total' => 1],
            ],
        ])]);
    }

    private function setting(string $key, string $value, string $type): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => 'talenta', 'type' => $type, 'label' => $key]);
        Setting::flushCache();
    }

    public function test_does_nothing_when_disabled(): void
    {
        $this->artisan('talenta:sync-employees')->assertSuccessful();

        $this->assertSame(0, TalentaEmployeeSyncRun::count());
        Http::assertNothingSent();
    }

    public function test_enabled_runs_pull_then_sync_and_respects_interval(): void
    {
        $this->setting('talenta.employee_sync', '1', 'boolean');
        $this->setting('talenta.employee_sync_interval', '60', 'number');

        $this->artisan('talenta:sync-employees')->assertSuccessful();

        $this->assertSame(['pull', 'sync'], TalentaEmployeeSyncRun::orderBy('created_at')->orderBy('type')->pluck('type')->all());
        $this->assertSame(0, TalentaEmployeeSyncRun::where('status', '!=', 'success')->count());
        $this->assertSame('Ani', Employee::where('talenta_user_id', '1')->value('name'));

        // Interval belum lewat -> tidak ada run baru.
        $this->artisan('talenta:sync-employees')->assertSuccessful();
        $this->assertSame(2, TalentaEmployeeSyncRun::count());

        $this->travel(61)->minutes();
        $this->artisan('talenta:sync-employees')->assertSuccessful();
        $this->assertSame(4, TalentaEmployeeSyncRun::count());
    }

    public function test_force_pull_only_skips_sync_stage(): void
    {
        $this->artisan('talenta:sync-employees', ['--force' => true, '--pull-only' => true])->assertSuccessful();

        $this->assertSame(['pull'], TalentaEmployeeSyncRun::pluck('type')->all());
        $this->assertSame(0, Employee::count());
    }

    public function test_failed_pull_skips_sync_stage(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(['*/employee*' => Http::response(['status' => 'error', 'message' => 'Unauthorized'], 401)]);

        $this->artisan('talenta:sync-employees', ['--force' => true])->assertFailed();

        $sync = TalentaEmployeeSyncRun::where('type', 'sync')->first();
        $this->assertSame('failed', $sync->status);
        $this->assertStringContainsString('Dilewati', $sync->error_message);
    }

    public function test_dispatch_runs_through_queue(): void
    {
        // phpunit.xml: QUEUE_CONNECTION=sync -> job langsung dieksekusi.
        $runs = app(TalentaEmployeeSyncRunner::class)->dispatch('both');

        $this->assertCount(2, $runs);
        $this->assertSame(['success', 'success'], array_map(fn ($r) => $r->refresh()->status, $runs));
        $this->assertTrue(Employee::where('talenta_user_id', '1')->exists());
    }

    public function test_refuses_to_start_while_another_run_is_active(): void
    {
        TalentaEmployeeSyncRun::create(['type' => 'pull', 'status' => 'running']);

        $this->expectException(RuntimeException::class);
        app(TalentaEmployeeSyncRunner::class)->start('both');
    }

    public function test_stale_active_run_is_failed_and_new_run_allowed(): void
    {
        $stale = TalentaEmployeeSyncRun::create(['type' => 'pull', 'status' => 'running']);
        $stale->forceFill(['created_at' => now()->subHours(3)])->save();

        $runs = app(TalentaEmployeeSyncRunner::class)->start('pull');

        $this->assertCount(1, $runs);
        $this->assertSame('failed', $stale->refresh()->status);
    }

    public function test_held_lock_fails_runs_instead_of_running_twice(): void
    {
        $lock = Cache::lock('talenta-employee-sync', 60);
        $lock->get();

        $runner = app(TalentaEmployeeSyncRunner::class);
        $runner->execute($runner->start('both'));

        $this->assertSame(['failed', 'failed'], TalentaEmployeeSyncRun::pluck('status')->all());
        Http::assertNothingSent();
        $lock->release();
    }
}
