<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** B2: retensi foto absen (attendance:prune-photos). */
class PruneAttendancePhotosTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::flushCache();
        config(['adms.photo_disk' => 'local']);
    }

    private function retention(int $days): void
    {
        Setting::updateOrCreate(
            ['key' => 'attendance.photo_retention_days'],
            ['value' => (string) $days, 'group' => 'storage', 'type' => 'number', 'label' => 'Retensi'],
        );
        Setting::flushCache();
    }

    private function log(int $ageDays, string $status = 'pending', string $disk = 'local'): AttendanceLog
    {
        $path = 'attendance-photos/2026/01/foto-'.(++$this->seq).'.jpg';
        Storage::disk($disk)->put($path, 'jpeg-bytes');

        $log = AttendanceLog::create([
            'source' => 'manual_photo',
            'timestamp' => now()->subDays($ageDays)->addSeconds($this->seq),
            'status_sync' => $status,
            'photo_path' => $path,
        ]);
        DB::table('attendance_logs')->where('id', $log->id)->update([
            'created_at' => now()->subDays($ageDays),
            'updated_at' => now()->subDays($ageDays),
        ]);

        return $log->refresh();
    }

    public function test_deletes_old_photos_but_keeps_the_log(): void
    {
        $this->retention(30);
        $old = $this->log(40, 'sent');
        $fresh = $this->log(5, 'sent');
        $path = $old->photo_path;
        $updatedAt = $old->updated_at;

        $this->artisan('attendance:prune-photos')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        $old->refresh();
        $this->assertNull($old->photo_path);
        $this->assertSame('sent', $old->status_sync);
        $this->assertEquals($updatedAt, $old->updated_at);

        Storage::disk('local')->assertExists($fresh->photo_path);
        $this->assertNotNull($fresh->refresh()->photo_path);
        $this->assertSame(2, AttendanceLog::count());
    }

    public function test_pending_review_is_never_touched(): void
    {
        $this->retention(30);
        $log = $this->log(400, 'pending_review');

        $this->artisan('attendance:prune-photos')->assertSuccessful();

        Storage::disk('local')->assertExists($log->photo_path);
        $this->assertNotNull($log->refresh()->photo_path);
    }

    public function test_deletes_from_object_storage_and_legacy_local_disk(): void
    {
        Storage::fake('idcloudhost');
        config([
            'adms.photo_disk' => 'idcloudhost',
            'filesystems.disks.idcloudhost.bucket' => 'bucket-test',
            'filesystems.disks.idcloudhost.key' => 'AK',
            'filesystems.disks.idcloudhost.secret' => 'SK',
        ]);
        $this->retention(30);

        $onS3 = $this->log(40, 'sent', 'idcloudhost');
        $onLocal = $this->log(40, 'rejected', 'local');

        $this->artisan('attendance:prune-photos')->assertSuccessful();

        Storage::disk('idcloudhost')->assertMissing($onS3->photo_path);
        Storage::disk('local')->assertMissing($onLocal->photo_path);
        $this->assertNull($onS3->refresh()->photo_path);
        $this->assertNull($onLocal->refresh()->photo_path);
    }

    public function test_missing_file_still_clears_photo_path(): void
    {
        $this->retention(30);
        $log = $this->log(40, 'sent');
        Storage::disk('local')->delete($log->photo_path);

        $this->artisan('attendance:prune-photos')->assertSuccessful();

        $this->assertNull($log->refresh()->photo_path);
        $audit = AuditLog::where('action', 'attendance.photos_pruned')->sole();
        $this->assertSame(0, $audit->changes['deleted']);
        $this->assertSame(1, $audit->changes['missing']);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->retention(30);
        $log = $this->log(40, 'sent');
        $this->log(5, 'sent');

        $this->artisan('attendance:prune-photos', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 1 foto')
            ->assertSuccessful();

        Storage::disk('local')->assertExists($log->photo_path);
        $this->assertNotNull($log->refresh()->photo_path);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_zero_retention_does_nothing(): void
    {
        $log = $this->log(4000, 'sent'); // default setting = 0

        $this->artisan('attendance:prune-photos')->assertSuccessful();

        Storage::disk('local')->assertExists($log->photo_path);
        $this->assertNotNull($log->refresh()->photo_path);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_days_option_overrides_setting(): void
    {
        $log = $this->log(10, 'sent');

        $this->artisan('attendance:prune-photos', ['--days' => 7])->assertSuccessful();

        $this->assertNull($log->refresh()->photo_path);
    }

    public function test_records_audit_summary(): void
    {
        $this->retention(30);
        $this->log(40, 'sent');
        $this->log(50, 'failed');

        $this->artisan('attendance:prune-photos')->assertSuccessful();

        $audit = AuditLog::where('action', 'attendance.photos_pruned')->sole();
        $this->assertSame(30, $audit->changes['retention_days']);
        $this->assertSame(2, $audit->changes['deleted']);
        $this->assertSame(0, $audit->changes['failed']);
        $this->assertArrayHasKey('cutoff', $audit->changes);
    }

    public function test_photo_url_is_null_after_pruning(): void
    {
        $this->retention(30);
        $log = $this->log(40, 'sent');

        $kept = $this->log(5, 'sent');

        $this->artisan('attendance:prune-photos')->assertSuccessful();

        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'admin@adms.local', 'password' => Hash::make('rahasia123'), 'role' => 'admin',
        ]));
        $logs = collect($this->get('/attendance-logs')->assertOk()->viewData('page')['props']['logs'])->keyBy('id');

        $this->assertNull($logs[$log->id]['manual']['photo_url']);
        $this->assertSame("/api/attendance-logs/{$kept->id}/photo", $logs[$kept->id]['manual']['photo_url']);
    }
}
