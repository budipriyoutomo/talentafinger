<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Machine;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserScope;
use App\Support\WorkDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** R3: laporan pengecualian absensi per hari kerja. */
class AttendanceExceptionTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-04';

    private Outlet $outlet;
    private Machine $machine;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();

        $this->outlet = $this->outlet('Kopi Sudirman');
        $this->machine = Machine::create(['serial_number' => 'SN1', 'name' => 'Mesin 1', 'outlet_id' => $this->outlet->id, 'is_active' => true]);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@adms.local', 'password' => Hash::make('password'), 'role' => 'admin']);
    }

    private function outlet(string $name): Outlet
    {
        $company = Company::firstOrCreate(['name' => 'PT Uji']);
        $brand = Brand::firstOrCreate(['company_id' => $company->id, 'name' => 'Brand Uji']);

        return Outlet::create(['brand_id' => $brand->id, 'name' => $name]);
    }

    private function employee(string $name, string $pin, ?Outlet $outlet = null, bool $active = true): Employee
    {
        $employee = Employee::create(['name' => $name, 'biometric_id' => $pin, 'is_active' => $active]);
        $employee->outlets()->sync([($outlet ?? $this->outlet)->id]);

        return $employee;
    }

    private function machineLog(Employee $employee, string $at, string $status = 'sent', ?Machine $machine = null): AttendanceLog
    {
        return AttendanceLog::create([
            'machine_id' => ($machine ?? $this->machine)->id,
            'biometric_id_lokal' => $employee->biometric_id,
            'timestamp' => $at,
            'status_sync' => $status,
        ]);
    }

    private function qrLog(Employee $employee, string $at, string $status = 'skipped', ?array $flags = null, ?Outlet $outlet = null): AttendanceLog
    {
        return AttendanceLog::create([
            'source' => AttendanceLog::SOURCE_QR,
            'employee_id' => $employee->id,
            'outlet_id' => ($outlet ?? $this->outlet)->id,
            'biometric_id_lokal' => $employee->biometric_id,
            'timestamp' => $at,
            'status_sync' => $status,
            'risk_flags' => $flags,
        ]);
    }

    private function report(array $query = [], ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->admin)
            ->get('/attendance-exceptions?' . http_build_query(['date_from' => self::DAY, 'date_to' => self::DAY, ...$query]))
            ->assertOk();

        return $response->viewData('page')['props'];
    }

    /** @return array<string, string[]> nama karyawan => jenis pengecualian */
    private function typesByName(array $props): array
    {
        return collect($props['rows'])->mapWithKeys(fn ($r) => [$r['name'] => $r['types']])->all();
    }

    private function cutoff(string $value): void
    {
        Setting::updateOrCreate(
            ['key' => 'attendance.workday_cutoff'],
            ['value' => $value, 'group' => 'attendance', 'type' => 'time', 'label' => 'Cutoff'],
        );
        Setting::flushCache();
    }

    public function test_each_exception_type(): void
    {
        $none = $this->employee('A Tanpa Absen', '1');
        $single = $this->employee('B Sekali', '2');
        $ok = $this->employee('C Lengkap', '3');
        $review = $this->employee('D Review', '4');
        $failed = $this->employee('E Gagal', '5');
        $flagged = $this->employee('F Flag', '6');

        $this->machineLog($single, self::DAY.' 08:00:00');
        $this->machineLog($ok, self::DAY.' 08:00:00');
        $this->machineLog($ok, self::DAY.' 17:00:00');
        $this->qrLog($review, self::DAY.' 08:00:00', 'pending_review');
        $this->qrLog($review, self::DAY.' 17:00:00');
        $this->machineLog($failed, self::DAY.' 08:00:00', 'failed');
        $this->machineLog($failed, self::DAY.' 17:00:00');
        $this->qrLog($flagged, self::DAY.' 08:00:00', 'skipped', ['new_device']);
        $this->qrLog($flagged, self::DAY.' 17:00:00');

        $props = $this->report();
        $types = $this->typesByName($props);

        $this->assertSame(['no_attendance'], $types['A Tanpa Absen']);
        $this->assertSame(['single_punch'], $types['B Sekali']);
        $this->assertArrayNotHasKey('C Lengkap', $types);
        $this->assertSame(['review_pending'], $types['D Review']);
        $this->assertSame(['send_failed'], $types['E Gagal']);
        $this->assertSame(['risk_flagged'], $types['F Flag']);

        $this->assertSame(1, $props['summary']['no_attendance']);
        $this->assertSame(1, $props['summary']['single_punch']);
        $this->assertSame(6, $props['employee_count']);
        $this->assertSame(5, $props['per_outlet'][0]['no_attendance'] + $props['per_outlet'][0]['single_punch']
            + $props['per_outlet'][0]['review_pending'] + $props['per_outlet'][0]['send_failed'] + $props['per_outlet'][0]['risk_flagged']);
    }

    public function test_duplicate_and_rejected_are_ignored(): void
    {
        $e = $this->employee('Siti', '1');
        $this->machineLog($e, self::DAY.' 08:00:00', 'duplicate');
        $this->qrLog($e, self::DAY.' 17:00:00', 'rejected');

        $this->assertSame(['no_attendance'], $this->typesByName($this->report())['Siti']);
    }

    public function test_repeated_scans_within_five_minutes_count_once(): void
    {
        $e = $this->employee('Siti', '1');
        $this->machineLog($e, self::DAY.' 08:00:00');
        $this->machineLog($e, self::DAY.' 08:03:00');
        $this->machineLog($e, self::DAY.' 08:04:59');

        $props = $this->report();
        $this->assertSame(['single_punch'], $this->typesByName($props)['Siti']);
        $this->assertSame(1, $props['rows'][0]['punches']);
    }

    public function test_cutoff_merges_night_shift_into_one_workday(): void
    {
        $this->cutoff('05:00');
        $e = $this->employee('Siti', '1');
        $this->machineLog($e, self::DAY.' 22:00:00');
        $this->machineLog($e, '2026-11-05 01:30:00'); // dini hari = hari kerja tgl 4

        $this->assertArrayNotHasKey('Siti', $this->typesByName($this->report()));
        $this->assertSame('2026-11-04', WorkDay::dateOf('2026-11-05 01:30:00'));
        $this->assertSame('2026-11-05', WorkDay::dateOf('2026-11-05 05:00:00'));
    }

    public function test_midnight_cutoff_equals_calendar_date(): void
    {
        $e = $this->employee('Siti', '1');
        $this->machineLog($e, self::DAY.' 22:00:00');
        $this->machineLog($e, '2026-11-05 01:30:00');

        $this->assertSame(['single_punch'], $this->typesByName($this->report())['Siti']);
        $this->assertSame('2026-11-05', WorkDay::dateOf('2026-11-05 00:00:00'));
        $this->assertSame('2026-11-04', WorkDay::dateOf('2026-11-04 23:59:59'));
    }

    public function test_machine_log_matched_by_pin(): void
    {
        $e = $this->employee('Siti', '2001');
        $other = $this->employee('Budi', '2002');
        $this->machineLog($e, self::DAY.' 08:00:00');

        $types = $this->typesByName($this->report());
        $this->assertSame(['single_punch'], $types['Siti']);
        $this->assertSame(['no_attendance'], $types['Budi']);
    }

    public function test_inactive_employee_is_not_listed(): void
    {
        $this->employee('Resign', '1', active: false);

        $props = $this->report();
        $this->assertArrayNotHasKey('Resign', $this->typesByName($props));
        $this->assertSame(0, $props['employee_count']);
    }

    public function test_multi_outlet_employee_counted_once_and_present_via_any_outlet(): void
    {
        $other = $this->outlet('Kopi Lain');
        $e = $this->employee('Siti', '1');
        $e->outlets()->sync([$this->outlet->id, $other->id]);
        $this->qrLog($e, self::DAY.' 08:00:00', outlet: $other);
        $this->qrLog($e, self::DAY.' 17:00:00', outlet: $other);
        $absent = $this->employee('Budi', '2');
        $absent->outlets()->sync([$this->outlet->id, $other->id]);

        $props = $this->report();
        $this->assertArrayNotHasKey('Siti', $this->typesByName($props));
        $this->assertCount(1, collect($props['rows'])->where('name', 'Budi'));
    }

    public function test_supervisor_only_sees_outlets_in_scope(): void
    {
        $other = $this->outlet('Kopi Lain');
        $this->employee('Siti', '1');
        $this->employee('Orang Lain', '2', $other);

        $manager = User::create(['name' => 'Manager', 'email' => 'm@adms.local', 'password' => Hash::make('password'), 'role' => 'manager']);
        UserScope::create(['user_id' => $manager->id, 'scope_type' => 'outlet', 'scope_id' => $this->outlet->id]);

        $types = $this->typesByName($this->report(as: $manager));
        $this->assertArrayHasKey('Siti', $types);
        $this->assertArrayNotHasKey('Orang Lain', $types);

        // Filter outlet di luar scope = kosong, bukan bocor.
        $this->assertSame([], $this->report(['outlet_id' => $other->id], $manager)['rows']);
    }

    public function test_range_over_31_days_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->get('/attendance-exceptions?date_from=2026-10-01&date_to=2026-11-01')
            ->assertSessionHasErrors('date_to');

        $this->actingAs($this->admin)
            ->get('/attendance-exceptions?date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk();
    }

    public function test_defaults_to_yesterday(): void
    {
        $props = $this->actingAs($this->admin)->get('/attendance-exceptions')->assertOk()->viewData('page')['props'];

        $this->assertSame(today()->subDay()->toDateString(), $props['filters']['date_from']);
        $this->assertSame(today()->subDay()->toDateString(), $props['filters']['date_to']);
    }

    public function test_type_filter_and_multi_day_rows(): void
    {
        $this->employee('Siti', '1');
        $e = $this->employee('Budi', '2');
        $this->machineLog($e, '2026-11-03 08:00:00');

        $props = $this->report(['date_from' => '2026-11-03', 'date_to' => self::DAY, 'type' => 'single_punch']);

        $this->assertCount(1, $props['rows']);
        $this->assertSame('Budi', $props['rows'][0]['name']);
        $this->assertSame('2026-11-03', $props['rows'][0]['date']);
    }

    public function test_requires_attendance_view_permission(): void
    {
        $employeeUser = User::create(['name' => 'K', 'email' => 'k@adms.local', 'password' => Hash::make('password'), 'role' => 'employee']);

        $this->actingAs($employeeUser)->get('/attendance-exceptions')->assertForbidden();
    }

    public function test_csv_export(): void
    {
        $this->employee('Siti', '1');

        $response = $this->actingAs($this->admin)
            ->get('/attendance-exceptions/export?date_from='.self::DAY.'&date_to='.self::DAY)
            ->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Hari Kerja', $csv);
        $this->assertStringContainsString('Siti', $csv);
        $this->assertStringContainsString('Tidak ada absen', $csv);
    }
}
