<?php

namespace Tests\Feature;

use App\Models\AttendanceKiosk;
use App\Models\AttendanceLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Setting;
use App\Services\QrTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint publik tablet kiosk: aktivasi kode -> token device -> /kiosk/state.
 */
class KioskDeviceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceKiosk $kiosk;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::flushCache();
        config(['attendance.qr_enabled' => true, 'attendance.qr_rotate_seconds' => 30]);

        $company = Company::create(['name' => 'PT Maju']);
        $brand = Brand::create(['company_id' => $company->id, 'name' => 'Kopi']);
        $outlet = Outlet::create(['brand_id' => $brand->id, 'name' => 'Kopi Sudirman']);

        $this->kiosk = new AttendanceKiosk(['outlet_id' => $outlet->id, 'name' => 'Kasir Depan']);
        $this->code = $this->kiosk->issueActivationCode();
        $this->kiosk->save();
    }

    private function activate(?string $code = null)
    {
        return $this->postJson('/kiosk/activate', ['code' => $code ?? $this->code]);
    }

    private function state(?string $token)
    {
        return $this->getJson('/kiosk/state', $token === null ? [] : ['X-Kiosk-Token' => $token]);
    }

    public function test_halaman_kiosk_publik(): void
    {
        $this->get('/kiosk')->assertOk();
    }

    public function test_aktivasi_kode_benar_dapat_token_dan_kode_hangus(): void
    {
        $token = $this->activate()
            ->assertOk()
            ->assertJsonPath('kiosk.name', 'Kasir Depan')
            ->assertJsonPath('kiosk.outlet_name', 'Kopi Sudirman')
            ->json('token');

        $kiosk = $this->kiosk->fresh();
        $this->assertSame(hash('sha256', $token), $kiosk->device_token_hash);
        $this->assertNotNull($kiosk->activated_at);
        $this->assertNull($kiosk->activation_code_hash);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kiosk.activated', 'target_id' => $kiosk->id]);

        $this->activate()->assertStatus(422);
    }

    public function test_kode_toleran_huruf_kecil_spasi_dan_tanda_hubung(): void
    {
        $messy = ' ' . strtolower(substr($this->code, 0, 4)) . '-' . substr($this->code, 4) . ' ';

        $this->activate($messy)->assertOk();
    }

    public function test_kode_salah_atau_kedaluwarsa_ditolak(): void
    {
        $this->activate('ZZZZZZZZ')->assertStatus(422)->assertJsonPath('message', 'Kode salah atau kedaluwarsa.');

        $this->travel(31)->minutes();
        $this->activate()->assertStatus(422);
        $this->assertNull($this->kiosk->fresh()->device_token_hash);
    }

    public function test_kode_kiosk_nonaktif_ditolak(): void
    {
        $this->kiosk->update(['is_active' => false]);

        $this->activate()->assertStatus(422);
    }

    public function test_aktivasi_dibatasi_throttle(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->activate('SALAH' . $i)->assertStatus(422);
        }

        $this->activate()->assertStatus(429);
    }

    public function test_state_tanpa_atau_token_salah_401(): void
    {
        $this->state(null)->assertUnauthorized();
        $this->state('token-ngawur')->assertUnauthorized();
    }

    public function test_state_berisi_qr_yang_lolos_verify_dan_catat_last_seen(): void
    {
        $token = $this->activate()->json('token');

        $res = $this->state($token)
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('rotate_seconds', 30)
            ->assertJsonPath('kiosk.outlet_name', 'Kopi Sudirman');

        parse_str((string) parse_url($res->json('qr.url'), PHP_URL_QUERY), $query);
        $verified = app(QrTokenService::class)->verify($query['k'], $query['w'], $query['s']);
        $this->assertTrue($this->kiosk->is($verified));
        $this->assertGreaterThan(0, $res->json('qr.expires_in'));

        $kiosk = $this->kiosk->fresh();
        $this->assertNotNull($kiosk->last_seen_at);
        $this->assertTrue($kiosk->isOnline());
    }

    public function test_qr_dimatikan_qr_null(): void
    {
        $token = $this->activate()->json('token');
        config(['attendance.qr_enabled' => false]);

        $this->state($token)->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('qr', null);
    }

    public function test_reset_aktivasi_token_lama_401(): void
    {
        $token = $this->activate()->json('token');

        $this->kiosk->fresh()->resetActivation();

        $this->state($token)->assertUnauthorized();
    }

    public function test_kiosk_nonaktif_token_401(): void
    {
        $token = $this->activate()->json('token');
        $this->kiosk->update(['is_active' => false]);

        $this->state($token)->assertUnauthorized();
    }

    public function test_feed_recent_hanya_qr_hari_ini_nama_depan_tanpa_ditolak(): void
    {
        $token = $this->activate()->json('token');
        $employee = Employee::create(['name' => 'Siti Aminah', 'biometric_id' => '2001', 'is_active' => true]);
        $tz = Setting::value('adms.device_timezone', 'Asia/Jakarta');

        $log = fn (array $attrs) => AttendanceLog::create($attrs + [
            'source' => AttendanceLog::SOURCE_QR,
            'employee_id' => $employee->id,
            'outlet_id' => $this->kiosk->outlet_id,
            'kiosk_id' => $this->kiosk->id,
            'biometric_id_lokal' => '2001',
            'timestamp' => now($tz)->format('Y-m-d H:i:s'),
            'status_sync' => 'pending_review',
        ]);
        $log([]);
        $log(['status_sync' => 'rejected']);
        $log(['timestamp' => now($tz)->subDay()->format('Y-m-d H:i:s')]);

        $recent = $this->state($token)->assertOk()->json('recent');

        $this->assertCount(1, $recent);
        $this->assertSame('Siti', $recent[0]['name']);
        $this->assertSame('pending_review', $recent[0]['status']);
    }
}
