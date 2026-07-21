<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\AttendanceSyncService;
use App\Services\MekariTalentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    public function test_was_accepted_true_when_2xx_without_errors(): void
    {
        $service = new MekariTalentaService();
        $response = Http::fake(['*' => Http::response(['code' => 200, 'message' => 'Success'], 200)])
            ->get('https://x.test');

        $this->assertTrue($service->wasAccepted($response));
    }

    public function test_was_accepted_false_when_2xx_but_body_has_errors(): void
    {
        // Talenta bisa balas 200 tapi body berisi error validasi -> harus dianggap GAGAL.
        $service = new MekariTalentaService();
        $body = ['message' => 'input validation error', 'errors' => ['setting absence not found for this company']];
        $response = Http::fake(['*' => Http::response($body, 200)])->get('https://x.test');

        $this->assertFalse($service->wasAccepted($response));
    }

    public function test_was_accepted_false_when_http_not_successful(): void
    {
        $service = new MekariTalentaService();
        $response = Http::fake(['*' => Http::response(['message' => 'bad'], 400)])->get('https://x.test');

        $this->assertFalse($service->wasAccepted($response));
    }

    public function test_import_fingerprint_saves_request_and_response_files(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response(['code' => 200], 200)]);

        $service = new MekariTalentaService();
        $service->importFingerprint("badgeno;date;checktime\n1001;2026-06-15;08:03:00\n");

        $files = Storage::disk('local')->files('talenta');
        $csvFiles = array_filter($files, fn ($f) => str_ends_with($f, '.csv'));
        $respFiles = array_filter($files, fn ($f) => str_ends_with($f, '.response.txt'));

        $this->assertNotEmpty($csvFiles, 'CSV request harus tersimpan');
        $this->assertNotEmpty($respFiles, 'Response Talenta harus tersimpan');
    }

    public function test_warns_when_csv_mendekati_batas_5mb(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response(['code' => 200], 200)]);
        Log::spy();

        $service = new MekariTalentaService();
        // 4.6MB > 85% dari 5MB -> harus memicu warning.
        $service->importFingerprint(str_repeat('x', (int) (4.6 * 1024 * 1024)));

        Log::shouldHaveReceived('warning')
            ->with('Talenta CSV mendekati batas ukuran file 5MB', \Mockery::type('array'))
            ->once();
    }

    public function test_tidak_warning_untuk_csv_ukuran_normal(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response(['code' => 200], 200)]);
        Log::spy();

        $service = new MekariTalentaService();
        $service->importFingerprint("badgeno;date;checktime\n1001;2026-06-15;08:03:00\n");

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Sanity check untuk komentar di AttendanceSyncService::CHUNK_SIZE: pastikan
     * angka itu tetap jauh di bawah batas 5MB walau worst-case badgeno 100 karakter
     * utf8mb4 (400 byte). Kalau test ini merah, komentar penjelasan chunk size
     * di AttendanceSyncService perlu ditulis ulang juga.
     */
    public function test_chunk_size_aman_terhadap_worst_case_badgeno(): void
    {
        $worstCaseRowBytes = 400 /* badgeno utf8mb4 100 char */ + 10 /* date */ + 8 /* checktime */ + 3 /* delimiter+newline */;
        $worstCaseTotal = AttendanceSyncService::CHUNK_SIZE * $worstCaseRowBytes;

        $this->assertLessThan(5 * 1024 * 1024 * 0.85, $worstCaseTotal,
            'CHUNK_SIZE * worst-case row size harus tetap di bawah ambang warning 85% dari 5MB');
    }
}
