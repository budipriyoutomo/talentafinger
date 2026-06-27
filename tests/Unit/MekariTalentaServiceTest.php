<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\MekariTalentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MekariTalentaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Tanpa baris di tabel settings, Setting::value() jatuh ke config().
        Setting::flushCache();
        config([
            'mekari.client_id' => 'test-client',
            'mekari.client_secret' => 'test-secret',
        ]);
    }

    public function test_build_signature_returns_expected_hmac_header(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 08:00:00', 'UTC'));

        $service = new MekariTalentaService();
        $path = '/v2/talenta/v2/attendance/import-fingerprint';

        $headers = $service->buildSignature('POST', $path);

        // Recompute pakai algoritma yang sama: string-to-sign = date + request-line.
        $date = now()->toRfc7231String();
        $payload = "date: {$date}\nPOST {$path} HTTP/1.1";
        $expectedSignature = base64_encode(hash_hmac('sha256', $payload, 'test-secret', true));

        $this->assertEquals($date, $headers['Date']);
        $this->assertEquals('application/json', $headers['Accept']);
        $this->assertStringContainsString('hmac username="test-client"', $headers['Authorization']);
        $this->assertStringContainsString('algorithm="hmac-sha256"', $headers['Authorization']);
        $this->assertStringContainsString('headers="date request-line"', $headers['Authorization']);
        $this->assertStringContainsString("signature=\"{$expectedSignature}\"", $headers['Authorization']);

        Carbon::setTestNow();
    }

    public function test_signature_changes_with_different_path(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 08:00:00', 'UTC'));

        $service = new MekariTalentaService();

        $sig1 = $service->buildSignature('POST', '/v2/talenta/v2/attendance/import-fingerprint');
        $sig2 = $service->buildSignature('GET', '/v2/talenta/v2/employee');

        $this->assertNotEquals($sig1['Authorization'], $sig2['Authorization']);

        Carbon::setTestNow();
    }

    public function test_builds_fingerprint_csv(): void
    {
        $service = new MekariTalentaService();

        $csv = $service->buildFingerprintCsv([
            ['1001', Carbon::parse('2026-06-15 08:03:00')],
        ]);

        $this->assertStringContainsString('badgeno;date;checktime', $csv);
        $this->assertStringContainsString('1001;2026-06-15;08:03:00', $csv);
    }
}
