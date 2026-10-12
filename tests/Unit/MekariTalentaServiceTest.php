<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\AttendanceSyncService;
use App\Services\MekariTalentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
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

    public function test_retry_saat_gagal_konek_lalu_berhasil(): void
    {
        Storage::fake('local');
        Sleep::fake();
        Http::fakeSequence()
            ->pushFailedConnection('cURL error 7: Failed to connect to api.mekari.com port 443')
            ->push(['code' => 200], 200);

        $response = (new MekariTalentaService())->importFingerprint("badgeno;date;checktime\n1001;2026-06-15;08:03:00\n");

        $this->assertSame(200, $response->status());
        Http::assertSentCount(2);
    }

    public function test_retry_untuk_503_lalu_berhasil(): void
    {
        Storage::fake('local');
        Sleep::fake();
        Http::fakeSequence()
            ->push('Service Unavailable', 503)
            ->push(['code' => 200], 200);

        $response = (new MekariTalentaService())->importFingerprint("badgeno;date;checktime\n1001;2026-06-15;08:03:00\n");

        $this->assertSame(200, $response->status());
        Http::assertSentCount(2);
    }

    public function test_tidak_retry_untuk_4xx_validasi(): void
    {
        Storage::fake('local');
        Sleep::fake();
        Http::fake(['*' => Http::response(['message' => 'input validation error'], 400)]);

        $response = (new MekariTalentaService())->importFingerprint("badgeno;date;checktime\n1001;2026-06-15;08:03:00\n");

        $this->assertSame(400, $response->status());
        Http::assertSentCount(1);
    }

    public function test_tidak_retry_saat_timeout_menunggu_jawaban(): void
    {
        // Timeout setelah request terkirim: Talenta mungkin sudah memproses CSV-nya,
        // upload ulang berisiko menggandakan data. Harus langsung gagal.
        Storage::fake('local');
        Sleep::fake();
        Http::fakeSequence()
            ->pushFailedConnection('cURL error 28: Operation timed out after 120000 milliseconds with 0 bytes received')
            ->push(['code' => 200], 200);

        try {
            (new MekariTalentaService())->importFingerprint("badgeno;date;checktime\n1001;2026-06-15;08:03:00\n");
            $this->fail('ConnectionException seharusnya dilempar');
        } catch (ConnectionException) {
            Http::assertSentCount(1);
        }
    }

    public function test_menyerah_setelah_3_percobaan(): void
    {
        Storage::fake('local');
        Sleep::fake();
        Http::fake(['*' => Http::response('Bad Gateway', 502)]);

        $response = (new MekariTalentaService())->importFingerprint("badgeno;date;checktime\n1001;2026-06-15;08:03:00\n");

        $this->assertSame(502, $response->status());
        Http::assertSentCount(3);
    }

    public function test_rate_limit_menunggu_saat_kuota_habis(): void
    {
        Storage::fake('local');
        Sleep::fake();
        config(['mekari.rate_limit' => 1]);
        Http::fake(['*' => Http::response(['code' => 200], 200)]);

        $service = new MekariTalentaService();
        $service->importFingerprint("badgeno;date;checktime\n1001;2026-06-15;08:03:00\n");
        Sleep::assertNeverSlept();

        // Upload kedua di menit yang sama melebihi kuota 1/menit -> harus menunggu.
        $service->importFingerprint("badgeno;date;checktime\n1001;2026-06-15;08:04:00\n");
        Sleep::assertSleptTimes(1);
        Http::assertSentCount(2);
    }

    public function test_log_request_tidak_memuat_isi_csv(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response(['code' => 200], 200)]);
        Log::spy();

        (new MekariTalentaService())->importFingerprint("badgeno;date;checktime\nRAHASIA-99;2026-06-15;08:03:00\n");

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context) => $message === 'Talenta REQUEST import-fingerprint'
                && ! array_key_exists('csv', $context)
                && ! str_contains(json_encode($context), 'RAHASIA-99')
                && $context['rows'] === 1)
            ->once();
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
