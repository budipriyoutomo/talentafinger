<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** R1-A: review massal absen foto & QR (POST /api/attendance-logs/review-bulk). */
class ReviewBulkTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private Employee $employee;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();

        $this->outlet = $this->outlet('Kopi Sudirman');
        $this->employee = Employee::create([
            'name' => 'Siti', 'talenta_employee_id' => 'TAL-1', 'biometric_id' => '2001', 'is_active' => true,
        ]);
    }

    private function outlet(string $name): Outlet
    {
        $company = Company::firstOrCreate(['name' => 'PT Uji']);
        $brand = Brand::firstOrCreate(['company_id' => $company->id, 'name' => 'Brand Uji']);

        return Outlet::create(['brand_id' => $brand->id, 'name' => $name, 'latitude' => -6.2, 'longitude' => 106.8]);
    }

    private function managerOf(Outlet $outlet, string $email = 'manager@adms.local'): User
    {
        $manager = User::create([
            'name' => 'Manager', 'email' => $email, 'password' => Hash::make('password'), 'role' => 'manager',
        ]);
        UserScope::create(['user_id' => $manager->id, 'scope_type' => 'outlet', 'scope_id' => $outlet->id]);

        return $manager;
    }

    private function log(string $source = AttendanceLog::SOURCE_MANUAL_PHOTO, string $status = 'pending_review', ?Outlet $outlet = null): AttendanceLog
    {
        return AttendanceLog::create([
            'source' => $source,
            'employee_id' => $this->employee->id,
            'outlet_id' => ($outlet ?? $this->outlet)->id,
            'biometric_id_lokal' => '2001',
            'timestamp' => now()->subMinutes(++$this->seq)->format('Y-m-d H:i:s'),
            'status_sync' => $status,
            'photo_path' => 'attendance-photos/x.jpg',
            'distance_meters' => 10,
            'accuracy_meters' => 12,
        ]);
    }

    private function bulk(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->managerOf($this->outlet))
            ->postJson('/api/attendance-logs/review-bulk', $payload);
    }

    public function test_bulk_approve(): void
    {
        $a = $this->log();
        $b = $this->log();

        $this->bulk(['ids' => [$a->id, $b->id], 'action' => 'approve'])
            ->assertOk()
            ->assertJson(['approved' => 2, 'rejected' => 0, 'already_reviewed' => 0, 'forbidden' => 0]);

        foreach ([$a, $b] as $log) {
            $log->refresh();
            $this->assertSame('pending', $log->status_sync);
            $this->assertNotNull($log->reviewed_by);
            $this->assertNotNull($log->reviewed_at);
        }
        $this->assertSame(2, AuditLog::where('action', 'attendance.manual_approved')->count());
    }

    public function test_bulk_reject_with_note(): void
    {
        $a = $this->log();
        $b = $this->log();

        $this->bulk(['ids' => [$a->id, $b->id], 'action' => 'reject', 'note' => 'Foto buram'])
            ->assertOk()
            ->assertJson(['approved' => 0, 'rejected' => 2]);

        $this->assertSame('rejected', $a->fresh()->status_sync);
        $this->assertSame('Foto buram', $b->fresh()->review_note);
        $this->assertSame(2, AuditLog::where('action', 'attendance.manual_rejected')->count());
    }

    public function test_reject_requires_note(): void
    {
        $log = $this->log();

        $this->bulk(['ids' => [$log->id], 'action' => 'reject'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->assertSame('pending_review', $log->fresh()->status_sync);
    }

    public function test_more_than_200_ids_rejected(): void
    {
        $ids = array_map(fn () => (string) Str::uuid(), range(1, 201));

        $this->bulk(['ids' => $ids, 'action' => 'approve'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_other_outlet_logs_are_forbidden_and_untouched(): void
    {
        $mine = $this->log();
        $other = $this->log(outlet: $this->outlet('Kopi Lain'));
        $machine = $this->log(AttendanceLog::SOURCE_MACHINE);
        $unknown = (string) Str::uuid();

        $this->bulk(['ids' => [$mine->id, $other->id, $machine->id, $unknown], 'action' => 'approve'])
            ->assertOk()
            ->assertJson(['approved' => 1, 'forbidden' => 3]);

        $this->assertSame('pending', $mine->fresh()->status_sync);
        $this->assertSame('pending_review', $other->fresh()->status_sync);
        $this->assertSame('pending_review', $machine->fresh()->status_sync);
        $this->assertSame(1, AuditLog::count());
    }

    public function test_already_reviewed_logs_are_counted_not_overwritten(): void
    {
        $fresh = $this->log();
        $done = $this->log(status: 'rejected');

        $this->bulk(['ids' => [$fresh->id, $done->id], 'action' => 'approve'])
            ->assertOk()
            ->assertJson(['approved' => 1, 'already_reviewed' => 1]);

        $this->assertSame('rejected', $done->fresh()->status_sync);
    }

    public function test_approved_qr_is_skipped_and_photo_is_pending(): void
    {
        $qr = $this->log(AttendanceLog::SOURCE_QR);
        $photo = $this->log(AttendanceLog::SOURCE_MANUAL_PHOTO);

        $this->bulk(['ids' => [$qr->id, $photo->id], 'action' => 'approve'])->assertOk();

        // Bawaan master sumber: QR tidak di-sync ke Talenta.
        $this->assertSame('skipped', $qr->fresh()->status_sync);
        $this->assertSame('pending', $photo->fresh()->status_sync);
    }

    public function test_user_without_review_permission_gets_403(): void
    {
        $log = $this->log();
        $operator = User::create([
            'name' => 'Op', 'email' => 'op@adms.local', 'password' => Hash::make('password'), 'role' => 'operator',
        ]);

        $this->bulk(['ids' => [$log->id], 'action' => 'approve'], $operator)->assertForbidden();

        $this->assertSame('pending_review', $log->fresh()->status_sync);
    }
}
