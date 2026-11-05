<?php

namespace Tests\Feature;

use App\Http\Controllers\ManualAttendanceController;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserScope;
use App\Services\AttendanceDeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** R4: deteksi titip absen lewat cookie perangkat (menandai, tidak memblokir). */
class DeviceCheckTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = -6.2;
    private const LNG = 106.8166667;
    private const COOKIE = AttendanceDeviceService::COOKIE;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::flushCache();
        config([
            'attendance.manual_photo_enabled' => true,
            'attendance.manual_photo_radius' => 150,
        ]);

        $company = Company::create(['name' => 'PT Uji']);
        $brand = Brand::create(['company_id' => $company->id, 'name' => 'Brand Uji']);
        $this->outlet = Outlet::create(['brand_id' => $brand->id, 'name' => 'Kopi Sudirman', 'latitude' => self::LAT, 'longitude' => self::LNG]);
    }

    private function employeeUser(string $name): User
    {
        $employee = Employee::create([
            'name' => $name,
            'talenta_employee_id' => 'TAL-'.$name,
            'biometric_id' => (string) random_int(1000, 9999),
            'is_active' => true,
            'allow_manual_attendance' => true,
        ]);
        $employee->outlets()->sync([$this->outlet->id]);

        return User::create([
            'name' => $name,
            'email' => Str::lower($name).'@adms.local',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'employee_id' => $employee->id,
        ]);
    }

    /** Absen foto dari "perangkat" bercookie $device (null = browser tanpa cookie). */
    private function submit(User $user, ?string $device): TestResponse
    {
        $request = $this->actingAs($user);
        if ($device !== null) {
            $request = $request->withUnencryptedCookie(self::COOKIE, $device);
        }

        $response = $request->post('/api/attendance/manual', [
            'photo' => UploadedFile::fake()->image('selfie.jpg', 640, 480),
            'latitude' => self::LAT + 0.0001,
            'longitude' => self::LNG,
            'accuracy' => 12,
        ], ['Accept' => 'application/json']);

        // Lewati cooldown antar-absen untuk submit berikutnya.
        $this->travel(ManualAttendanceController::COOLDOWN_MINUTES + 1)->minutes();

        return $response;
    }

    private function lastFlags(User $user): array
    {
        return AttendanceLog::where('employee_id', $user->employee_id)->latest('created_at')->first()->risk_flags ?? [];
    }

    public function test_first_device_is_registered_without_flag_and_cookie_is_issued(): void
    {
        $siti = $this->employeeUser('Siti');

        $response = $this->submit($siti, null)->assertCreated();

        $cookie = $response->getCookie(self::COOKIE, false);
        $this->assertNotNull($cookie);
        $this->assertTrue(Str::isUuid($cookie->getValue()));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());

        $this->assertSame([], $this->lastFlags($siti));
        $this->assertNull(AttendanceLog::sole()->getRawOriginal('risk_flags'));
        $this->assertSame(1, EmployeeDevice::where('employee_id', $siti->employee_id)->count());
    }

    public function test_same_device_again_has_no_flag(): void
    {
        $siti = $this->employeeUser('Siti');
        $device = (string) Str::uuid();

        $this->submit($siti, $device)->assertCreated();
        $this->submit($siti, $device)->assertCreated();

        $this->assertSame([], $this->lastFlags($siti));
        $this->assertSame(1, EmployeeDevice::count());
    }

    public function test_second_device_gets_new_device_flag(): void
    {
        $siti = $this->employeeUser('Siti');

        $this->submit($siti, (string) Str::uuid())->assertCreated();
        $this->submit($siti, (string) Str::uuid())->assertCreated();

        $this->assertSame([AttendanceDeviceService::FLAG_NEW_DEVICE], $this->lastFlags($siti));
    }

    public function test_two_employees_on_one_device_get_shared_device_flag(): void
    {
        $siti = $this->employeeUser('Siti');
        $budi = $this->employeeUser('Budi');
        $device = (string) Str::uuid();

        $this->submit($siti, $device)->assertCreated();
        $this->submit($budi, $device)->assertCreated();

        // Perangkat pertama Budi: tanpa new_device, tapi shared_device tetap dicek.
        $this->assertSame([AttendanceDeviceService::FLAG_SHARED_DEVICE], $this->lastFlags($budi));
    }

    public function test_shared_device_only_counts_last_30_days(): void
    {
        $siti = $this->employeeUser('Siti');
        $budi = $this->employeeUser('Budi');
        $device = (string) Str::uuid();

        $this->submit($siti, $device)->assertCreated();
        $this->travel(31)->days();
        $this->submit($budi, $device)->assertCreated();

        $this->assertSame([], $this->lastFlags($budi));
    }

    public function test_reset_device_is_not_counted_as_active(): void
    {
        $siti = $this->employeeUser('Siti');
        $old = (string) Str::uuid();

        $this->submit($siti, $old)->assertCreated();
        EmployeeDevice::query()->update(['revoked_at' => now()]);

        $this->submit($siti, (string) Str::uuid())->assertCreated();

        $this->assertSame([], $this->lastFlags($siti));
    }

    public function test_off_setting_produces_no_flags_and_no_devices(): void
    {
        Setting::create(['key' => 'attendance.device_check', 'value' => '0', 'group' => 'attendance', 'type' => 'boolean', 'label' => 'Device']);
        Setting::flushCache();
        $siti = $this->employeeUser('Siti');
        $budi = $this->employeeUser('Budi');
        $device = (string) Str::uuid();

        $this->submit($siti, $device)->assertCreated();
        $this->submit($budi, $device)->assertCreated();

        $this->assertSame([], $this->lastFlags($budi));
        $this->assertSame(0, EmployeeDevice::count());
    }

    public function test_flags_never_block_attendance(): void
    {
        $siti = $this->employeeUser('Siti');
        $budi = $this->employeeUser('Budi');
        $device = (string) Str::uuid();

        $this->submit($siti, $device)->assertCreated();
        $this->submit($siti, (string) Str::uuid())->assertCreated();
        $this->submit($budi, $device)->assertCreated();

        $this->assertSame(3, AttendanceLog::count());
        $this->assertSame(3, AttendanceLog::where('status_sync', 'pending_review')->count());
    }

    public function test_database_never_stores_raw_cookie_value(): void
    {
        $siti = $this->employeeUser('Siti');
        $device = (string) Str::uuid();

        $this->submit($siti, $device)->assertCreated();

        $row = EmployeeDevice::sole();
        $this->assertSame(hash('sha256', $device), $row->device_hash);
        foreach (['employee_devices', 'attendance_logs'] as $table) {
            $this->assertStringNotContainsString($device, json_encode(DB::table($table)->get()));
        }
    }

    public function test_invalid_cookie_value_is_replaced(): void
    {
        $siti = $this->employeeUser('Siti');

        $response = $this->submit($siti, 'bukan-uuid')->assertCreated();

        $this->assertTrue(Str::isUuid($response->getCookie(self::COOKIE, false)->getValue()));
        $this->assertNotSame(hash('sha256', 'bukan-uuid'), EmployeeDevice::sole()->device_hash);
    }

    public function test_flagged_filter_and_flags_shown_on_logs_page(): void
    {
        $siti = $this->employeeUser('Siti');
        $this->submit($siti, (string) Str::uuid())->assertCreated();
        $this->submit($siti, (string) Str::uuid())->assertCreated();

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@adms.local', 'password' => Hash::make('password'), 'role' => 'admin']);
        $logs = $this->actingAs($admin)->get('/attendance-logs?flagged=1')->assertOk()->viewData('page')['props']['logs'];

        $this->assertCount(1, $logs);
        $this->assertSame([AttendanceDeviceService::FLAG_NEW_DEVICE], $logs[0]['manual']['risk_flags']);
    }

    // ===== Daftar & reset perangkat =====

    private function managerOf(Outlet $outlet, string $email = 'manager@adms.local'): User
    {
        $manager = User::create(['name' => 'Manager', 'email' => $email, 'password' => Hash::make('password'), 'role' => 'manager']);
        UserScope::create(['user_id' => $manager->id, 'scope_type' => 'outlet', 'scope_id' => $outlet->id]);

        return $manager;
    }

    public function test_manager_can_list_and_reset_device_with_audit(): void
    {
        $siti = $this->employeeUser('Siti');
        $this->submit($siti, (string) Str::uuid())->assertCreated();
        $device = EmployeeDevice::sole();
        $manager = $this->managerOf($this->outlet);

        $this->actingAs($manager)->getJson("/api/employees/{$siti->employee_id}/devices")
            ->assertOk()
            ->assertJsonCount(1, 'devices')
            ->assertJsonMissingPath('devices.0.device_hash');

        $this->actingAs($manager)->deleteJson("/api/employees/{$siti->employee_id}/devices/{$device->id}")->assertOk();

        $this->assertNotNull($device->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee.device_reset', 'target_id' => $siti->employee_id]);
    }

    public function test_manager_of_other_outlet_cannot_reset_device(): void
    {
        $siti = $this->employeeUser('Siti');
        $this->submit($siti, (string) Str::uuid())->assertCreated();
        $device = EmployeeDevice::sole();
        $other = Outlet::create(['brand_id' => $this->outlet->brand_id, 'name' => 'Kopi Lain']);
        $stranger = $this->managerOf($other, 'm2@adms.local');

        $this->actingAs($stranger)->getJson("/api/employees/{$siti->employee_id}/devices")->assertForbidden();
        $this->actingAs($stranger)->deleteJson("/api/employees/{$siti->employee_id}/devices/{$device->id}")->assertForbidden();

        $this->assertNull($device->fresh()->revoked_at);
        $this->assertSame(0, AuditLog::where('action', 'employee.device_reset')->count());
    }
}
