<?php

namespace Tests\Unit;

use App\Models\AttendanceKiosk;
use App\Models\AttendanceLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Outlet;
use App\Models\Setting;
use App\Services\QrTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Token QR dinamis (absen QR per outlet): window waktu + HMAC per kiosk.
 */
class QrTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private QrTokenService $service;
    private AttendanceKiosk $kiosk;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::flushCache();
        config(['attendance.qr_rotate_seconds' => 30]);
        // Tepat di awal sebuah window 30 detik supaya perhitungan mudah dibaca.
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));

        $this->service = new QrTokenService();
        $this->kiosk = $this->activatedKiosk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function activatedKiosk(): AttendanceKiosk
    {
        $company = Company::firstOrCreate(['name' => 'PT Uji']);
        $brand = Brand::firstOrCreate(['company_id' => $company->id, 'name' => 'Brand Uji']);
        $outlet = Outlet::firstOrCreate(['brand_id' => $brand->id, 'name' => 'Kopi Sudirman']);

        $kiosk = AttendanceKiosk::create(['outlet_id' => $outlet->id, 'name' => 'Kasir Depan']);
        $kiosk->device_token_hash = hash('sha256', Str::random(64));
        $kiosk->activated_at = now();
        $kiosk->save();

        return $kiosk->fresh();
    }

    private function verifyWindow(int $window, ?string $signature = null): ?AttendanceKiosk
    {
        return $this->service->verify(
            $this->kiosk->id,
            (string) $window,
            $signature ?? $this->service->sign($this->kiosk, $window),
        );
    }

    public function test_current_window_verifies(): void
    {
        $window = $this->service->currentWindow();

        $this->assertTrue($this->kiosk->is($this->verifyWindow($window)));
    }

    public function test_previous_window_still_verifies_but_older_and_future_do_not(): void
    {
        $window = $this->service->currentWindow();

        $this->assertNotNull($this->verifyWindow($window - 1));
        $this->assertNull($this->verifyWindow($window - 2));
        $this->assertNull($this->verifyWindow($window + 1));
    }

    public function test_qr_expires_after_two_windows(): void
    {
        $window = $this->service->currentWindow();

        Carbon::setTestNow(now()->addSeconds(30));
        $this->assertNotNull($this->verifyWindow($window));

        Carbon::setTestNow(now()->addSeconds(30));
        $this->assertNull($this->verifyWindow($window));
    }

    public function test_tampered_signature_fails(): void
    {
        $window = $this->service->currentWindow();
        $signature = $this->service->sign($this->kiosk, $window);
        $tampered = substr($signature, 0, -1) . ($signature[-1] === 'a' ? 'b' : 'a');

        $this->assertNull($this->verifyWindow($window, $tampered));
    }

    public function test_signature_of_other_window_is_rejected(): void
    {
        $window = $this->service->currentWindow();

        $this->assertNull($this->verifyWindow($window, $this->service->sign($this->kiosk, $window - 1)));
    }

    public function test_malformed_input_fails(): void
    {
        $window = $this->service->currentWindow();
        $signature = $this->service->sign($this->kiosk, $window);

        $this->assertNull($this->service->verify(null, (string) $window, $signature));
        $this->assertNull($this->service->verify('bukan-uuid', (string) $window, $signature));
        $this->assertNull($this->service->verify((string) Str::uuid(), (string) $window, $signature));
        $this->assertNull($this->service->verify($this->kiosk->id, "{$window}abc", $signature));
        $this->assertNull($this->service->verify($this->kiosk->id, [$window], $signature));
        $this->assertNull($this->service->verify($this->kiosk->id, (string) $window, null));
        $this->assertNull($this->service->verify($this->kiosk->id, (string) $window, ''));
    }

    public function test_inactive_kiosk_fails(): void
    {
        $window = $this->service->currentWindow();
        $this->kiosk->update(['is_active' => false]);

        $this->assertNull($this->verifyWindow($window));
    }

    public function test_not_activated_kiosk_fails(): void
    {
        $window = $this->service->currentWindow();
        $this->kiosk->device_token_hash = null;
        $this->kiosk->save();

        $this->assertNull($this->verifyWindow($window));
    }

    public function test_rotated_secret_invalidates_old_token(): void
    {
        $window = $this->service->currentWindow();
        $oldSignature = $this->service->sign($this->kiosk, $window);

        $this->kiosk->secret = Str::random(64);
        $this->kiosk->save();

        $this->assertNull($this->verifyWindow($window, $oldSignature));
        $this->assertNotNull($this->service->verify(
            $this->kiosk->id,
            (string) $window,
            $this->service->sign($this->kiosk->fresh(), $window),
        ));
    }

    public function test_url_for_contains_verifiable_token_and_seconds_left(): void
    {
        Carbon::setTestNow(now()->addSeconds(12));

        $qr = $this->service->urlFor($this->kiosk);
        parse_str((string) parse_url($qr['url'], PHP_URL_QUERY), $query);

        $this->assertStringContainsString('/absen/qr?', $qr['url']);
        $this->assertSame($this->service->currentWindow(), $qr['window']);
        $this->assertSame(18, $qr['expires_in']);
        $this->assertNotNull($this->service->verify($query['k'], $query['w'], $query['s']));
    }

    public function test_rotate_seconds_is_clamped(): void
    {
        config(['attendance.qr_rotate_seconds' => 5]);
        $this->assertSame(QrTokenService::ROTATE_MIN, $this->service->rotateSeconds());

        config(['attendance.qr_rotate_seconds' => 9999]);
        $this->assertSame(QrTokenService::ROTATE_MAX, $this->service->rotateSeconds());

        config(['attendance.qr_rotate_seconds' => 0]);
        $this->assertSame(QrTokenService::ROTATE_DEFAULT, $this->service->rotateSeconds());
    }

    public function test_secret_is_generated_encrypted_and_hidden(): void
    {
        $raw = \DB::table('attendance_kiosks')->where('id', $this->kiosk->id)->value('secret');

        $this->assertSame(64, strlen($this->kiosk->secret));
        $this->assertNotSame($this->kiosk->secret, $raw);
        $this->assertArrayNotHasKey('secret', $this->kiosk->toArray());
        $this->assertArrayNotHasKey('device_token_hash', $this->kiosk->toArray());
    }

    public function test_qr_log_counts_as_employee_submission(): void
    {
        $this->assertTrue((new AttendanceLog(['source' => AttendanceLog::SOURCE_QR]))->isManual());
        $this->assertTrue((new AttendanceLog(['source' => AttendanceLog::SOURCE_MANUAL_PHOTO]))->isManual());
        $this->assertFalse((new AttendanceLog(['source' => AttendanceLog::SOURCE_MACHINE]))->isManual());
    }
}
