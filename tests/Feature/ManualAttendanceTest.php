<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserScope;
use App\Services\AttendanceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Absen manual pakai foto + geofence + approval supervisor (modul D).
 */
class ManualAttendanceTest extends TestCase
{
    use RefreshDatabase;

    /** Titik pusat outlet uji (Jakarta). ~0.00045 derajat lintang ≈ 50 m. */
    private const LAT = -6.2;
    private const LNG = 106.8166667;

    private Outlet $outlet;
    private Employee $employee;
    private User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Setting::flushCache();
        config([
            'attendance.manual_photo_enabled' => true,
            'attendance.manual_photo_radius' => 150,
            'mekari.base_url' => 'https://api.test/v2/talenta/v2',
            'mekari.client_id' => 'c',
            'mekari.client_secret' => 's',
        ]);

        $this->outlet = $this->outlet('Kopi Sudirman', self::LAT, self::LNG);

        $this->employee = Employee::create([
            'name' => 'Siti',
            'talenta_employee_id' => 'TAL-777',
            'biometric_id' => '2001',
            'is_active' => true,
            'allow_manual_attendance' => true,
        ]);
        $this->employee->outlets()->sync([$this->outlet->id]);

        $this->employeeUser = $this->user('employee', 'siti@adms.local', $this->employee->id);
    }

    private function outlet(string $name, ?float $lat = null, ?float $lng = null): Outlet
    {
        $company = Company::firstOrCreate(['name' => 'PT Uji']);
        $brand = Brand::firstOrCreate(['company_id' => $company->id, 'name' => 'Brand Uji']);

        return Outlet::create(['brand_id' => $brand->id, 'name' => $name, 'latitude' => $lat, 'longitude' => $lng]);
    }

    private function user(string $role, string $email, ?string $employeeId = null): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'employee_id' => $employeeId,
        ]);
    }

    private function managerOf(Outlet $outlet, string $email = 'manager@adms.local'): User
    {
        $manager = $this->user('manager', $email);
        UserScope::create(['user_id' => $manager->id, 'scope_type' => 'outlet', 'scope_id' => $outlet->id]);

        return $manager;
    }

    private function submit(float $latOffset = 0.00045, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->employeeUser)->post('/api/attendance/manual', [
            'photo' => UploadedFile::fake()->image('selfie.jpg', 640, 480),
            'latitude' => self::LAT + $latOffset,
            'longitude' => self::LNG,
            'accuracy' => 12,
        ], ['Accept' => 'application/json']);
    }

    private function manualLog(string $status = 'pending_review', ?Outlet $outlet = null): AttendanceLog
    {
        return AttendanceLog::create([
            'source' => AttendanceLog::SOURCE_MANUAL_PHOTO,
            'employee_id' => $this->employee->id,
            'outlet_id' => ($outlet ?? $this->outlet)->id,
            'biometric_id_lokal' => '2001',
            'timestamp' => '2026-10-12 08:00:00',
            'status_sync' => $status,
            'photo_path' => 'attendance-photos/x.jpg',
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'distance_meters' => 10,
        ]);
    }

    // ===== Submit & geofence =====

    public function test_absen_dalam_radius_tersimpan_sebagai_pending_review(): void
    {
        $this->submit()->assertCreated();

        $log = AttendanceLog::sole();
        $this->assertSame(AttendanceLog::SOURCE_MANUAL_PHOTO, $log->source);
        $this->assertSame('pending_review', $log->status_sync);
        $this->assertSame($this->employee->id, $log->employee_id);
        $this->assertSame($this->outlet->id, $log->outlet_id);
        $this->assertNull($log->machine_id);
        $this->assertEqualsWithDelta(50, $log->distance_meters, 2);
        $this->assertSame(12, $log->accuracy_meters);
        Storage::disk('local')->assertExists($log->photo_path);
    }

    public function test_absen_di_luar_radius_ditolak_tanpa_log(): void
    {
        $this->submit(0.01)->assertStatus(422)->assertJsonPath('radius_meters', 150);

        $this->assertSame(0, AttendanceLog::count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_dicocokkan_ke_outlet_terdekat_milik_karyawan(): void
    {
        $farOutlet = $this->outlet('Kopi Jauh', self::LAT + 0.05, self::LNG);
        $this->employee->outlets()->sync([$farOutlet->id, $this->outlet->id]);

        $this->submit()->assertCreated();

        $this->assertSame($this->outlet->id, AttendanceLog::sole()->outlet_id);
    }

    public function test_outlet_tanpa_koordinat_ditolak(): void
    {
        $this->outlet->update(['latitude' => null, 'longitude' => null]);

        $this->submit()->assertForbidden()->assertJsonFragment(['message' => 'Outlet Anda belum punya titik koordinat. Hubungi admin.']);
    }

    public function test_karyawan_tanpa_outlet_ditolak(): void
    {
        $this->employee->outlets()->detach();

        $this->submit()->assertForbidden()->assertJsonFragment(['message' => 'Anda belum ditautkan ke outlet aktif mana pun. Hubungi admin.']);
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_outlet_nonaktif_tak_dipakai_geofence(): void
    {
        $this->outlet->update(['is_active' => false]);

        $this->submit()->assertForbidden()->assertJsonFragment(['message' => 'Anda belum ditautkan ke outlet aktif mana pun. Hubungi admin.']);

        // Outlet aktif lain yang JAUH tetap dipakai, dan outlet nonaktif yang dekat diabaikan.
        $far = $this->outlet('Kopi Jauh', self::LAT + 0.05, self::LNG);
        $this->employee->outlets()->attach($far->id);
        $this->submit()->assertStatus(422)->assertJsonPath('radius_meters', 150);
    }

    public function test_admin_menyimpan_dan_mempertahankan_koordinat_outlet(): void
    {
        $admin = $this->user('admin', 'admin@adms.local');
        $outlet = $this->outlet('Kopi Baru');

        $this->actingAs($admin)->putJson("/api/outlets/{$outlet->id}", [
            'brand_id' => $outlet->brand_id, 'name' => 'Kopi Baru', 'latitude' => -6.21, 'longitude' => 106.82,
        ])->assertOk();
        $this->assertTrue($outlet->fresh()->hasCoordinates());

        // Ganti nama saja (tanpa kirim koordinat) tak boleh menghapus titiknya.
        $this->actingAs($admin)->putJson("/api/outlets/{$outlet->id}", [
            'brand_id' => $outlet->brand_id, 'name' => 'Kopi Baru 2',
        ])->assertOk();
        $this->assertEqualsWithDelta(-6.21, $outlet->fresh()->latitude, 0.0000001);

        $this->actingAs($admin)->putJson("/api/outlets/{$outlet->id}", [
            'brand_id' => $outlet->brand_id, 'name' => 'Kopi Baru 2', 'latitude' => -6.21,
        ])->assertStatus(422)->assertJsonValidationErrors('longitude');
    }

    public function test_absen_kedua_dalam_cooldown_ditolak(): void
    {
        $this->submit()->assertCreated();
        $this->submit()->assertStatus(429);

        $this->assertSame(1, AttendanceLog::count());
    }

    public function test_ditolak_bila_fitur_dimatikan(): void
    {
        config(['attendance.manual_photo_enabled' => false]);

        $this->submit()->assertForbidden();
    }

    public function test_ditolak_bila_karyawan_belum_diizinkan(): void
    {
        $this->employee->update(['allow_manual_attendance' => false]);

        $this->submit()->assertForbidden();
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_akun_non_employee_tak_bisa_absen_foto(): void
    {
        $this->submit(as: $this->user('operator', 'op@adms.local'))->assertForbidden();
        $this->submit(as: $this->user('admin', 'admin@adms.local'))->assertForbidden();
    }

    // ===== Review supervisor =====

    public function test_manager_outlet_menyetujui_jadi_pending(): void
    {
        $log = $this->manualLog();

        $this->actingAs($this->managerOf($this->outlet))
            ->postJson("/api/attendance-logs/{$log->id}/review", ['action' => 'approve'])
            ->assertOk();

        $log->refresh();
        $this->assertSame('pending', $log->status_sync);
        $this->assertNotNull($log->reviewed_at);
        $this->assertNotNull($log->reviewed_by);
    }

    public function test_tolak_wajib_alasan(): void
    {
        $log = $this->manualLog();
        $manager = $this->managerOf($this->outlet);

        $this->actingAs($manager)
            ->postJson("/api/attendance-logs/{$log->id}/review", ['action' => 'reject'])
            ->assertStatus(422);

        $this->actingAs($manager)
            ->postJson("/api/attendance-logs/{$log->id}/review", ['action' => 'reject', 'note' => 'Foto bukan karyawan'])
            ->assertOk();

        $this->assertSame('rejected', $log->fresh()->status_sync);
        $this->assertSame('Foto bukan karyawan', $log->fresh()->review_note);
    }

    public function test_tak_bisa_review_dua_kali(): void
    {
        $log = $this->manualLog('pending');

        $this->actingAs($this->managerOf($this->outlet))
            ->postJson("/api/attendance-logs/{$log->id}/review", ['action' => 'approve'])
            ->assertStatus(422);
    }

    public function test_manager_outlet_lain_dan_operator_tak_boleh_review(): void
    {
        $log = $this->manualLog();
        $otherOutlet = $this->outlet('Kopi Lain', self::LAT, self::LNG);

        $this->actingAs($this->managerOf($otherOutlet, 'm2@adms.local'))
            ->postJson("/api/attendance-logs/{$log->id}/review", ['action' => 'approve'])
            ->assertForbidden();

        $this->actingAs($this->user('operator', 'op@adms.local'))
            ->postJson("/api/attendance-logs/{$log->id}/review", ['action' => 'approve'])
            ->assertForbidden();

        $this->assertSame('pending_review', $log->fresh()->status_sync);
    }

    // ===== Sinkronisasi ke Talenta =====

    public function test_belum_disetujui_tak_pernah_terkirim(): void
    {
        Http::fake();
        $log = $this->manualLog();
        $rejected = $this->manualLog('rejected');
        $rejected->update(['timestamp' => '2026-10-12 17:00:00']);

        app(AttendanceSyncService::class)->sendPending(includeFailed: true);
        app(AttendanceSyncService::class)->sendLogs(collect([$log, $rejected]));

        Http::assertNothingSent();
        $this->assertSame('pending_review', $log->fresh()->status_sync);
        $this->assertSame('rejected', $rejected->fresh()->status_sync);
    }

    public function test_disetujui_terkirim_dengan_badge_dari_employee_id(): void
    {
        Http::fake(['*' => Http::response(['code' => 200], 200)]);
        // PIN sengaja dikosongkan: log manual tak boleh bergantung pada PIN.
        $this->employee->update(['biometric_id' => null]);
        $log = $this->manualLog('pending');
        $log->update(['biometric_id_lokal' => null]);

        $result = app(AttendanceSyncService::class)->sendPending();

        $this->assertSame(1, $result['sent']);
        $this->assertSame('sent', $log->fresh()->status_sync);
        $csv = collect(Storage::disk('local')->files('talenta'))
            ->first(fn ($f) => str_ends_with($f, '.csv'));
        $this->assertStringContainsString('TAL-777;2026-10-12;08:00:00', Storage::disk('local')->get($csv));
    }

    public function test_log_manual_tanpa_talenta_id_gagal_dengan_pesan_yang_tepat(): void
    {
        Http::fake();
        $this->employee->update(['talenta_employee_id' => null]);
        $log = $this->manualLog('pending');

        app(AttendanceSyncService::class)->sendPending();

        Http::assertNothingSent();
        $this->assertSame('failed', $log->fresh()->status_sync);
        $this->assertStringContainsString('Talenta Employee ID', $log->fresh()->error_message);
    }

    // ===== Akses data & foto =====

    public function test_log_manual_hanya_terlihat_di_scope_outletnya(): void
    {
        $this->manualLog();
        $otherOutlet = $this->outlet('Kopi Lain', self::LAT, self::LNG);

        $this->assertSame(1, AttendanceLog::visibleTo($this->managerOf($this->outlet))->count());
        $this->assertSame(0, AttendanceLog::visibleTo($this->managerOf($otherOutlet, 'm2@adms.local'))->count());
    }

    public function test_foto_hanya_untuk_pemilik_dan_supervisor_berwenang(): void
    {
        Storage::disk('local')->put('attendance-photos/x.jpg', 'jpeg-bytes');
        $log = $this->manualLog();

        $otherEmployee = Employee::create([
            'name' => 'Budi', 'talenta_employee_id' => 'TAL-888', 'is_active' => true,
        ]);
        $otherUser = $this->user('employee', 'budi@adms.local', $otherEmployee->id);

        $this->actingAs($this->employeeUser)->get("/api/attendance-logs/{$log->id}/photo")->assertOk();
        $this->actingAs($this->managerOf($this->outlet))->get("/api/attendance-logs/{$log->id}/photo")->assertOk();
        $this->actingAs($otherUser)->get("/api/attendance-logs/{$log->id}/photo")->assertForbidden();
    }

    // ===== Akun karyawan =====

    public function test_akun_karyawan_diarahkan_ke_halaman_absen(): void
    {
        $this->post('/login', ['email' => 'siti@adms.local', 'password' => 'password'])
            ->assertRedirect('/absen');

        $this->actingAs($this->employeeUser)->get('/dashboard')->assertRedirect('/absen');
        $this->actingAs($this->employeeUser)->get('/absen')->assertOk();
        $this->actingAs($this->employeeUser)->get('/attendance-logs')->assertForbidden();
    }

    public function test_akun_karyawan_tak_bisa_membaca_statistik_dashboard(): void
    {
        $this->actingAs($this->employeeUser)->getJson('/api/dashboard-stats')->assertForbidden();
    }

    public function test_akun_role_employee_wajib_ditautkan_ke_karyawan(): void
    {
        $admin = $this->user('admin', 'admin@adms.local');
        $other = Employee::create(['name' => 'Budi', 'talenta_employee_id' => 'TAL-888', 'is_active' => true]);

        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Budi', 'email' => 'budi@adms.local', 'password' => 'password123', 'role' => 'employee',
        ])->assertStatus(422)->assertJsonValidationErrors('employee_id');

        // Satu karyawan hanya satu akun.
        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Siti 2', 'email' => 'siti2@adms.local', 'password' => 'password123',
            'role' => 'employee', 'employee_id' => $this->employee->id,
        ])->assertStatus(422)->assertJsonValidationErrors('employee_id');

        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Budi', 'email' => 'budi@adms.local', 'password' => 'password123',
            'role' => 'employee', 'employee_id' => $other->id,
        ])->assertCreated()->assertJsonPath('employee_id', $other->id);
    }
}
