<?php

namespace Tests\Feature;

use App\Models\AttendanceKiosk;
use App\Models\AttendanceLog;
use App\Models\AttendanceSource;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserScope;
use App\Services\AttendanceSyncService;
use App\Services\QrTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Absen QR per outlet: scan QR kiosk -> scan session -> selfie + geofence ->
 * pending_review -> review supervisor -> Talenta.
 */
class QrAttendanceTest extends TestCase
{
    use RefreshDatabase;

    /** Titik pusat outlet uji (Jakarta). ~0.00045 derajat lintang ≈ 50 m. */
    private const LAT = -6.2;
    private const LNG = 106.8166667;

    private Outlet $outlet;
    private AttendanceKiosk $kiosk;
    private Employee $employee;
    private User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Setting::flushCache();
        config([
            'attendance.qr_enabled' => true,
            'attendance.qr_radius' => 100,
            'attendance.qr_rotate_seconds' => 30,
            'attendance.qr_scan_ttl' => 180,
            'attendance.manual_photo_enabled' => true,
            'mekari.base_url' => 'https://api.test/v2/talenta/v2',
            'mekari.client_id' => 'c',
            'mekari.client_secret' => 's',
        ]);

        $this->outlet = $this->outlet('Kopi Sudirman', self::LAT, self::LNG);
        $this->kiosk = $this->kioskAt($this->outlet);

        $this->employee = Employee::create([
            'name' => 'Siti Aminah',
            'talenta_employee_id' => 'TAL-777',
            'biometric_id' => '2001',
            'is_active' => true,
            // Absen QR TIDAK butuh izin absen foto.
            'allow_manual_attendance' => false,
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

    private function kioskAt(Outlet $outlet): AttendanceKiosk
    {
        $kiosk = AttendanceKiosk::create(['outlet_id' => $outlet->id, 'name' => 'Kasir Depan']);
        $kiosk->activate();

        return $kiosk->fresh();
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

    /** URL relatif QR kiosk saat ini (isi QR di layar tablet). */
    private function qrUrl(?AttendanceKiosk $kiosk = null): string
    {
        $url = app(QrTokenService::class)->urlFor($kiosk ?? $this->kiosk)['url'];

        return '/absen/qr?' . parse_url($url, PHP_URL_QUERY);
    }

    private function scanAsEmployee(?AttendanceKiosk $kiosk = null)
    {
        return $this->actingAs($this->employeeUser)->get($this->qrUrl($kiosk));
    }

    private function submit(float $latOffset = 0.00045, ?float $accuracy = 9)
    {
        return $this->actingAs($this->employeeUser)->post('/api/attendance/qr', array_filter([
            'photo' => UploadedFile::fake()->image('selfie.jpg', 640, 480),
            'latitude' => self::LAT + $latOffset,
            'longitude' => self::LNG,
            'accuracy' => $accuracy,
        ], fn ($v) => $v !== null), ['Accept' => 'application/json']);
    }

    private function qrLog(string $status = 'pending_review', ?Outlet $outlet = null): AttendanceLog
    {
        return AttendanceLog::create([
            'source' => AttendanceLog::SOURCE_QR,
            'employee_id' => $this->employee->id,
            'outlet_id' => ($outlet ?? $this->outlet)->id,
            'kiosk_id' => $this->kiosk->id,
            'biometric_id_lokal' => '2001',
            'timestamp' => '2026-10-21 08:01:00',
            'status_sync' => $status,
            'photo_path' => 'attendance-photos/qr.jpg',
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'distance_meters' => 20,
        ]);
    }

    // ===== Scan =====

    public function test_scan_valid_saat_login_isi_scan_session_dan_redirect_url_bersih(): void
    {
        $this->scanAsEmployee()
            ->assertRedirect('/absen/qr')
            ->assertSessionHas('qr_scan.kiosk_id', $this->kiosk->id);

        $this->actingAs($this->employeeUser)->get('/absen/qr')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('QrCheckIn')
                ->where('scan.outlet_name', 'Kopi Sudirman')
                ->where('scan.kiosk_name', 'Kasir Depan')
                ->where('blocked_reason', null)
                ->where('qr_error', null));
    }

    public function test_scan_valid_sebagai_tamu_login_lalu_kembali_dengan_scan_utuh(): void
    {
        $this->get($this->qrUrl())
            ->assertRedirect('/login')
            ->assertSessionHas('qr_scan.kiosk_id', $this->kiosk->id);

        $this->post('/login', ['email' => 'siti@adms.local', 'password' => 'password'])
            ->assertRedirect(url('/absen/qr'));

        $this->assertSame($this->kiosk->id, session('qr_scan.kiosk_id'));
        $this->get('/absen/qr')->assertOk()
            ->assertInertia(fn ($page) => $page->where('scan.outlet_name', 'Kopi Sudirman'));
    }

    public function test_tamu_tanpa_query_diarahkan_ke_login(): void
    {
        $this->get('/absen/qr')->assertRedirect('/login');
    }

    public function test_scan_qr_kedaluwarsa_beri_pesan_tanpa_scan_session(): void
    {
        $url = $this->qrUrl();
        Carbon::setTestNow(now()->addSeconds(61));

        $this->actingAs($this->employeeUser)->get($url)
            ->assertRedirect('/absen/qr')
            ->assertSessionMissing('qr_scan');

        $this->actingAs($this->employeeUser)->get('/absen/qr')
            ->assertInertia(fn ($page) => $page
                ->where('scan', null)
                ->where('qr_error', 'QR sudah kedaluwarsa atau tidak valid. Scan ulang QR di layar outlet.'));

        Carbon::setTestNow();
    }

    public function test_scan_dengan_signature_palsu_ditolak(): void
    {
        $this->actingAs($this->employeeUser)
            ->get("/absen/qr?k={$this->kiosk->id}&w=1&s=palsu")
            ->assertSessionMissing('qr_scan');
    }

    public function test_akun_non_employee_tak_bisa_membuka_halaman_qr(): void
    {
        $this->actingAs($this->user('operator', 'op@adms.local'))->get('/absen/qr')->assertForbidden();
    }

    // ===== Submit =====

    public function test_submit_dalam_radius_tersimpan_pending_review_di_outlet_kiosk(): void
    {
        $this->scanAsEmployee();
        $scannedAt = now();
        $this->travel(90)->seconds();

        $this->submit()->assertCreated();

        $log = AttendanceLog::sole();
        $this->assertSame(AttendanceLog::SOURCE_QR, $log->source);
        $this->assertSame('pending_review', $log->status_sync);
        $this->assertSame($this->employee->id, $log->employee_id);
        $this->assertSame($this->outlet->id, $log->outlet_id);
        $this->assertSame($this->kiosk->id, $log->kiosk_id);
        $this->assertNull($log->machine_id);
        $this->assertSame('2001', $log->biometric_id_lokal);
        $this->assertEqualsWithDelta(50, $log->distance_meters, 2);
        $this->assertSame(9, $log->accuracy_meters);
        Storage::disk('local')->assertExists($log->photo_path);

        // Waktu absen = waktu SCAN dalam jam lokal perangkat, bukan waktu kirim.
        $tz = Setting::value('adms.device_timezone', 'Asia/Jakarta');
        $this->assertSame($scannedAt->setTimezone($tz)->format('Y-m-d H:i:s'), $log->timestamp->format('Y-m-d H:i:s'));

        // Satu scan = satu absen.
        $this->assertNull(session('qr_scan'));
    }

    public function test_submit_tanpa_scan_ditolak_tanpa_log_dan_file(): void
    {
        $this->submit()->assertStatus(422)->assertJsonPath('message', 'Waktu scan habis. Scan ulang QR di layar outlet.');

        $this->assertSame(0, AttendanceLog::count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_submit_setelah_ttl_scan_lewat_ditolak(): void
    {
        $this->scanAsEmployee();
        $this->travel(181)->seconds();

        $this->submit()->assertStatus(422);

        $this->assertSame(0, AttendanceLog::count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_karyawan_tak_terdaftar_di_outlet_kiosk_ditolak(): void
    {
        $other = $this->outlet('Kopi Lain', self::LAT, self::LNG);
        $this->scanAsEmployee($this->kioskAt($other));

        $this->submit()->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak terdaftar di outlet Kopi Lain. Hubungi admin.');
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_di_luar_radius_ditolak_tanpa_log_dan_file(): void
    {
        $this->scanAsEmployee();

        // ~0.0018 derajat ≈ 200 m > radius 100 m.
        $this->submit(0.0018)->assertStatus(422)->assertJsonPath('radius_meters', 100);

        $this->assertSame(0, AttendanceLog::count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_outlet_kiosk_tanpa_koordinat_ditolak(): void
    {
        $this->scanAsEmployee();
        $this->outlet->update(['latitude' => null, 'longitude' => null]);

        $this->submit()->assertStatus(422);
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_kiosk_dinonaktifkan_setelah_scan_ditolak(): void
    {
        $this->scanAsEmployee();
        $this->kiosk->update(['is_active' => false]);

        $this->submit()->assertStatus(422);
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_cooldown_lintas_sumber_setelah_absen_foto(): void
    {
        AttendanceLog::create([
            'source' => AttendanceLog::SOURCE_MANUAL_PHOTO,
            'employee_id' => $this->employee->id,
            'outlet_id' => $this->outlet->id,
            'biometric_id_lokal' => '2001',
            'timestamp' => now(),
            'status_sync' => 'pending_review',
        ]);
        $this->scanAsEmployee();

        $this->submit()->assertStatus(429);
        $this->assertSame(1, AttendanceLog::count());
    }

    public function test_akurasi_gps_buruk_atau_kosong_ditolak(): void
    {
        config(['attendance.qr_max_accuracy' => 100]);
        $this->scanAsEmployee();

        $this->submit(accuracy: 1500)->assertStatus(422)
            ->assertJsonPath('accuracy_meters', 1500)
            ->assertJsonPath('max_accuracy_meters', 100);
        $this->submit(accuracy: null)->assertStatus(422);
        $this->assertSame(0, AttendanceLog::count());

        // Tepat di batas masih diterima; scan session tak terpakai oleh penolakan tadi.
        $this->submit(accuracy: 100)->assertCreated();
        $this->assertSame(100, AttendanceLog::sole()->accuracy_meters);
    }

    public function test_kiriman_saat_kiriman_lain_masih_diproses_ditolak_409(): void
    {
        $this->scanAsEmployee();
        // Kiriman pertama (double-tap) masih memegang lock karyawan ini.
        $lock = Cache::lock("attendance-submit:{$this->employee->id}", 15);
        $this->assertTrue($lock->get());

        $this->submit()->assertStatus(409);
        $this->assertSame(0, AttendanceLog::count());

        $lock->release();
        $this->submit()->assertCreated();
    }

    public function test_fitur_dimatikan_ditolak(): void
    {
        $this->scanAsEmployee();
        config(['attendance.qr_enabled' => false]);

        $this->submit()->assertForbidden();
        $this->assertSame(0, AttendanceLog::count());
    }

    // ===== User role lain yang juga karyawan =====

    public function test_manager_yang_ditautkan_ke_karyawan_bisa_absen_qr(): void
    {
        // Akun khusus karyawan dilepas dulu (1 karyawan = 1 akun).
        $this->employeeUser->update(['employee_id' => null]);
        $manager = $this->managerOf($this->outlet);
        $manager->update(['employee_id' => $this->employee->id]);

        $this->assertTrue($manager->fresh()->hasPermission('attendance.qr.create'));
        $this->assertTrue($manager->fresh()->hasPermission('attendance.review'));

        $this->actingAs($manager)->get($this->qrUrl())->assertRedirect('/absen/qr');
        $this->actingAs($manager)->post('/api/attendance/qr', [
            'photo' => UploadedFile::fake()->image('selfie.jpg', 640, 480),
            'latitude' => self::LAT + 0.00045,
            'longitude' => self::LNG,
            'accuracy' => 9,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame($this->employee->id, AttendanceLog::sole()->employee_id);
        // Tetap pengguna panel: dashboard tidak dialihkan ke /absen.
        $this->actingAs($manager)->get('/dashboard')->assertOk();
    }

    public function test_manager_tanpa_tautan_karyawan_tak_bisa_absen_qr(): void
    {
        $manager = $this->managerOf($this->outlet);

        $this->assertFalse($manager->hasPermission('attendance.qr.create'));
        $this->actingAs($manager)->get('/absen/qr')->assertForbidden();
    }

    public function test_user_role_lain_boleh_ditautkan_ke_karyawan_lewat_api(): void
    {
        $this->employeeUser->update(['employee_id' => null]);
        $admin = $this->user('admin', 'admin@adms.local');

        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Budi Manager',
            'email' => 'budi@adms.local',
            'password' => 'Rahasia#2026x',
            'role' => 'manager',
            'employee_id' => $this->employee->id,
            'outlet_ids' => [$this->outlet->id],
        ])->assertCreated()->assertJsonPath('employee_id', $this->employee->id);
    }

    // ===== Review, foto & Talenta =====

    public function test_supervisor_outlet_bisa_review_dan_lihat_foto_outlet_lain_tidak(): void
    {
        Storage::disk('local')->put('attendance-photos/qr.jpg', 'jpeg-bytes');
        $log = $this->qrLog();
        $other = $this->outlet('Kopi Lain', self::LAT, self::LNG);
        $stranger = $this->managerOf($other, 'm2@adms.local');

        $this->actingAs($stranger)->get("/api/attendance-logs/{$log->id}/photo")->assertForbidden();
        $this->actingAs($stranger)->postJson("/api/attendance-logs/{$log->id}/review", ['action' => 'approve'])->assertForbidden();

        $manager = $this->managerOf($this->outlet);
        $this->actingAs($manager)->get("/api/attendance-logs/{$log->id}/photo")->assertOk();
        $this->actingAs($manager)->postJson("/api/attendance-logs/{$log->id}/review", ['action' => 'approve'])->assertOk();

        // Bawaan master sumber: QR tidak di-sync ke Talenta.
        $this->assertSame('skipped', $log->fresh()->status_sync);
    }

    public function test_disetujui_masuk_antrean_bila_sumber_qr_di_sync(): void
    {
        AttendanceSource::where('code', AttendanceLog::SOURCE_QR)->first()->update(['sync_to_talenta' => true]);
        $log = $this->qrLog();

        $this->actingAs($this->managerOf($this->outlet))
            ->postJson("/api/attendance-logs/{$log->id}/review", ['action' => 'approve'])->assertOk();

        $this->assertSame('pending', $log->fresh()->status_sync);
    }

    public function test_qr_pending_dilewati_bila_sumber_tidak_di_sync(): void
    {
        Http::fake();
        $log = $this->qrLog('pending');

        $result = app(AttendanceSyncService::class)->sendPending();

        $this->assertSame(0, $result['sent']);
        $this->assertSame('skipped', $log->fresh()->status_sync);
        Http::assertNothingSent();
    }

    public function test_disetujui_terkirim_ke_talenta_dengan_talenta_employee_id(): void
    {
        Http::fake(['*' => Http::response(['code' => 200], 200)]);
        AttendanceSource::where('code', AttendanceLog::SOURCE_QR)->first()->update(['sync_to_talenta' => true]);
        $log = $this->qrLog('pending');

        $result = app(AttendanceSyncService::class)->sendPending();

        $this->assertSame(1, $result['sent']);
        $this->assertSame('sent', $log->fresh()->status_sync);
        $csv = collect(Storage::disk('local')->files('talenta'))->first(fn ($f) => str_ends_with($f, '.csv'));
        $this->assertStringContainsString('TAL-777;2026-10-21;08:01:00', Storage::disk('local')->get($csv));
    }

    public function test_pending_review_qr_tak_pernah_terkirim(): void
    {
        Http::fake();
        $log = $this->qrLog();

        app(AttendanceSyncService::class)->sendPending(includeFailed: true);
        app(AttendanceSyncService::class)->sendLogs(collect([$log]));

        Http::assertNothingSent();
        $this->assertSame('pending_review', $log->fresh()->status_sync);
    }

    // ===== Laporan Logs =====

    public function test_halaman_logs_filter_qr_dan_tampilkan_nama_kiosk(): void
    {
        $this->qrLog();
        AttendanceLog::create([
            'source' => AttendanceLog::SOURCE_MANUAL_PHOTO,
            'employee_id' => $this->employee->id,
            'outlet_id' => $this->outlet->id,
            'biometric_id_lokal' => '2001',
            'timestamp' => '2026-10-21 09:00:00',
            'status_sync' => 'pending_review',
        ]);

        $this->actingAs($this->managerOf($this->outlet))
            ->get('/attendance-logs?source=qr')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('AttendanceLogs')
                ->has('logs', 1)
                ->where('logs.0.source', 'qr')
                ->where('logs.0.employee_name', 'Siti Aminah')
                ->where('logs.0.manual.outlet_name', 'Kopi Sudirman')
                ->where('logs.0.manual.kiosk_name', 'Kasir Depan'));
    }

    public function test_absen_foto_diblokir_beri_petunjuk_absen_qr(): void
    {
        $this->actingAs($this->employeeUser)->get('/absen')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ManualCheckIn')
                ->where('blocked_reason', 'Anda belum diizinkan absen pakai foto. Hubungi admin/manager outlet.')
                ->where('qr_available', true));
    }

    // ===== Beranda akun karyawan =====

    public function test_karyawan_tanpa_izin_absen_foto_mendarat_di_absen_qr(): void
    {
        $this->post('/login', ['email' => 'siti@adms.local', 'password' => 'password'])
            ->assertRedirect('/absen/qr');

        $this->employee->update(['allow_manual_attendance' => true]);
        $this->actingAs($this->employeeUser->fresh())->get('/dashboard')->assertRedirect('/absen');
    }
}
