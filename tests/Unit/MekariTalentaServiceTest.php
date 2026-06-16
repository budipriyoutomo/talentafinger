<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Carbon;
use App\Services\MekariTalentaService;

class MekariTalentaServiceTest extends TestCase
{
    public function test_build_signature_returns_expected_headers(): void
    {
        config(['mekari.client_secret' => 'test-secret']);
        Carbon::setTestNow(Carbon::parse('2026-06-15 08:00:00', 'UTC'));

        $service = new MekariTalentaService();
        $method = 'POST';
        $path = '/api/v1/attendance';
        $body = json_encode(['employee_id' => 'EMP001', 'check_time' => '2026-06-15T08:03:22+00:00']);

        $headers = $service->buildSignature($method, $path, $body);

        // Recompute expected values with the same algorithm (PRD section 7)
        $date = now()->format('D, d M Y H:i:s T');
        $bodyHash = base64_encode(hash('sha256', $body, true));
        $stringToSign = "date: {$date}\n{$method} {$path} HTTP/1.1\ndigest: SHA-256={$bodyHash}";
        $expectedSignature = base64_encode(hash_hmac('sha256', $stringToSign, 'test-secret', true));

        $this->assertEquals("X-Mekari-Signature {$expectedSignature}", $headers['Authorization']);
        $this->assertEquals($date, $headers['X-Mekari-Date']);
        $this->assertEquals("SHA-256={$bodyHash}", $headers['Digest']);
        $this->assertEquals('application/json', $headers['Content-Type']);

        Carbon::setTestNow();
    }

    public function test_signature_changes_with_different_body(): void
    {
        config(['mekari.client_secret' => 'test-secret']);
        Carbon::setTestNow(Carbon::parse('2026-06-15 08:00:00', 'UTC'));

        $service = new MekariTalentaService();

        $sig1 = $service->buildSignature('POST', '/api/v1/attendance', '{"a":1}');
        $sig2 = $service->buildSignature('POST', '/api/v1/attendance', '{"a":2}');

        $this->assertNotEquals($sig1['Authorization'], $sig2['Authorization']);
        $this->assertNotEquals($sig1['Digest'], $sig2['Digest']);

        Carbon::setTestNow();
    }
}
