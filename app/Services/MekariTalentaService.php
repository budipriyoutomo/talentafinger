<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Throwable;

class MekariTalentaService
{
    /**
     * Bangun header autentikasi HMAC sesuai spesifikasi Mekari.
     *
     * String-to-sign HANYA berisi 2 baris: header `date` dan request-line.
     * Body/digest TIDAK ikut ditandatangani.
     *   date: {RFC 7231 GMT}
     *   {METHOD} {path?query} HTTP/1.1
     *
     * @param  string  $path  Path lengkap termasuk query string (mis. /v2/talenta/v2/attendance/import-fingerprint)
     */
    public function buildSignature(string $method, string $path): array
    {
        $date = now()->toRfc7231String(); // RFC 7231, dipaksa GMT
        $requestLine = strtoupper($method) . " {$path} HTTP/1.1";
        $payload = "date: {$date}\n{$requestLine}";

        $signature = base64_encode(
            hash_hmac('sha256', $payload, Setting::value('mekari.client_secret'), true)
        );

        $clientId = Setting::value('mekari.client_id');

        return [
            'Accept' => 'application/json',
            'Date' => $date,
            'Authorization' => "hmac username=\"{$clientId}\", algorithm=\"hmac-sha256\", "
                . "headers=\"date request-line\", signature=\"{$signature}\"",
        ];
    }

    /**
     * Upload absensi ke Talenta lewat endpoint Import Fingerprint (multipart CSV).
     *
     * @param  string  $csvContent  Isi CSV dengan header: badgeno,checktime
     */
    /** Batas ukuran file Talenta untuk import-fingerprint. */
    private const MAX_CSV_BYTES = 5 * 1024 * 1024;

    /** Total percobaan upload (1 awal + 2 retry). */
    private const MAX_ATTEMPTS = 3;

    /** Kunci RateLimiter untuk upload ke Talenta (FR-09). */
    private const RATE_LIMIT_KEY = 'mekari-api:import-fingerprint';

    /** Batas lama menunggu kuota rate limit, supaya request web tak menggantung. */
    private const MAX_THROTTLE_WAIT_SECONDS = 60;

    /** Kunci RateLimiter untuk list karyawan (sync karyawan Talenta). */
    private const EMPLOYEE_RATE_LIMIT_KEY = 'mekari-api:employee';

    /** Talenta membatasi `limit` list karyawan ke 50 (nilai lebih besar tetap dibalas 50). */
    public const EMPLOYEE_PAGE_LIMIT = 50;

    /**
     * Ambil 1 halaman daftar karyawan: GET {base_url}/employee?limit=..&page=..
     *
     * Daftar sudah memuat karyawan Active DAN Resigned (tanpa filter status).
     * Beda dengan import, GET ini idempoten -> boleh di-retry untuk semua
     * kegagalan koneksi/5xx/429 (lihat isSafeToRetryRead).
     */
    public function listEmployees(int $page, int $limit = self::EMPLOYEE_PAGE_LIMIT): Response
    {
        $url = Setting::value('mekari.base_url') . '/employee?' . http_build_query(['limit' => $limit, 'page' => $page]);
        $parts = parse_url($url);
        // Signature HMAC wajib memakai path LENGKAP dengan query string.
        $path = $parts['path'] . '?' . $parts['query'];

        $this->throttle(self::EMPLOYEE_RATE_LIMIT_KEY);

        $response = Http::withHeaders($this->buildSignature('GET', $path))
            ->connectTimeout(10)
            ->timeout(60)
            ->retry(self::MAX_ATTEMPTS, fn (int $attempt) => $attempt * 1000, fn (Throwable $e) => $this->isSafeToRetryRead($e), throw: false)
            ->get($url);

        // Isi body TIDAK dicatat: berisi data pribadi karyawan.
        $context = ['page' => $page, 'limit' => $limit, 'status' => $response->status()];
        if ($response->successful()) {
            Log::info('Talenta list employee OK', $context + ['rows' => count($response->json('data.employees') ?? [])]);
        } else {
            Log::error('Talenta list employee GAGAL', $context + ['body' => mb_substr($response->body(), 0, 500)]);
        }

        return $response;
    }

    public function importFingerprint(string $csvContent): Response
    {
        $url = Setting::value('mekari.base_url') . '/attendance/import-fingerprint';
        $path = parse_url($url, PHP_URL_PATH);

        $headers = $this->buildSignature('POST', $path);

        // Simpan CSV yang dikirim sebagai file fisik agar bisa diperiksa manual.
        // Lokasi: storage/app/talenta/import-fingerprint-YYYYmmdd-His-uuuu.csv
        $stamp = now()->format('Ymd-His-u');
        $savedPath = "talenta/import-fingerprint-{$stamp}.csv";
        Storage::put($savedPath, $csvContent);

        // Jaga-jaga: AttendanceSyncService::CHUNK_SIZE dihitung dari asumsi
        // worst-case panjang badgeno, bukan jaminan. Kalau suatu saat asumsi itu
        // meleset (mis. format talenta_employee_id berubah jadi jauh lebih panjang),
        // ini memberi sinyal SEBELUM Talenta menolak filenya diam-diam.
        $sizeBytes = strlen($csvContent);
        if ($sizeBytes > self::MAX_CSV_BYTES * 0.85) {
            Log::warning('Talenta CSV mendekati batas ukuran file 5MB', [
                'size_bytes' => $sizeBytes,
                'limit_bytes' => self::MAX_CSV_BYTES,
                'saved_csv' => storage_path('app/' . $savedPath),
            ]);
        }

        // Log REQUEST ke laravel.log. Authorization HMAC di-redact (mengandung tanda tangan),
        // dan field token tidak ikut dicatat utuh demi keamanan. Isi CSV TIDAK ikut
        // dicatat: berisi data absensi karyawan (PII) dan bisa ribuan baris per
        // upload — salinan lengkapnya sudah ada di file `saved_csv`.
        Log::info('Talenta REQUEST import-fingerprint', [
            'url' => $url,
            'method' => 'POST',
            'headers' => ['Date' => $headers['Date'], 'Authorization' => '[redacted]'],
            'user_id' => Setting::value('mekari.fingerprint_user_id'),
            'saved_csv' => storage_path('app/' . $savedPath),
            'rows' => max(0, substr_count($csvContent, "\n") - 1),
            'size_bytes' => $sizeBytes,
        ]);

        $this->throttle();

        // Timeout eksplisit: tanpa ini satu upload yang menggantung bisa menahan
        // worker (atau request web) sampai PHP sendiri yang menyerah, dan status
        // log tak pernah sempat ditulis. connectTimeout dibuat pendek karena
        // "server tak terjangkau" tak perlu ditunggu lama; timeout total longgar
        // karena Talenta memproses CSV-nya dulu sebelum menjawab.
        //
        // Retry HANYA untuk kegagalan yang pasti belum diproses Talenta (lihat
        // isSafeToRetry). throw=false: respons non-2xx terakhir tetap dikembalikan
        // apa adanya supaya wasAccepted() & pencatatan error di bawah tetap jalan.
        $response = Http::withHeaders($headers)
            ->connectTimeout(10)
            ->timeout(120)
            ->retry(self::MAX_ATTEMPTS, fn (int $attempt) => $attempt * 1000, fn (Throwable $e) => $this->isSafeToRetry($e), throw: false)
            ->attach('file', $csvContent, 'attendance.csv')
            ->post($url, [
                'user_id' => Setting::value('mekari.fingerprint_user_id'),
                'token' => Setting::value('mekari.fingerprint_token'),
            ]);

        // Simpan response body berdampingan dengan file CSV (timestamp sama) untuk diaudit.
        $responsePath = "talenta/import-fingerprint-{$stamp}.response.txt";
        Storage::put($responsePath, "HTTP {$response->status()}\n\n" . $response->body());

        // Log RESPONSE ke laravel.log. Sukses -> info, gagal -> error.
        $context = [
            'status' => $response->status(),
            'saved_response' => storage_path('app/' . $responsePath),
            'body' => $response->body(),
        ];
        if ($response->successful()) {
            Log::info('Talenta RESPONSE import-fingerprint OK', $context);
        } else {
            Log::error('Talenta RESPONSE import-fingerprint GAGAL', $context);
        }

        return $response;
    }

    /**
     * Tentukan apakah Talenta benar-benar menerima data, bukan sekadar HTTP 2xx.
     *
     * Talenta bisa membalas 2xx tapi body berisi error validasi, mis.
     *   {"message":"input validation error","errors":[...],"request_id":"..."}
     * Anggap GAGAL bila status bukan 2xx, atau body punya array `errors` tidak kosong.
     */
    public function wasAccepted(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $errors = $response->json('errors');

        return empty($errors);
    }

    /**
     * FR-09: tegakkan batas request/menit ke Talenta (setting mekari.rate_limit).
     *
     * Kalau kuota menit ini habis, tunggu sampai jendela berikutnya terbuka —
     * dibatasi MAX_THROTTLE_WAIT_SECONDS supaya tombol "kirim" di web tidak
     * menggantung tanpa batas. Satu upload CSV = satu hit, retry di dalamnya
     * tidak dihitung terpisah.
     */
    private function throttle(string $key = self::RATE_LIMIT_KEY): void
    {
        $max = max(1, (int) Setting::value('mekari.rate_limit', 60));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $wait = min(RateLimiter::availableIn($key), self::MAX_THROTTLE_WAIT_SECONDS);

            Log::warning('Talenta rate limit tercapai, menunggu sebelum request', [
                'key' => $key,
                'limit_per_minute' => $max,
                'wait_seconds' => $wait,
            ]);

            Sleep::sleep($wait);
        }

        RateLimiter::hit($key, 60);
    }

    /** GET idempoten: aman diulang untuk gagal koneksi apa pun, 429, dan 5xx. */
    private function isSafeToRetryRead(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    /**
     * Boleh diulang hanya jika Talenta PASTI belum memproses CSV-nya, karena
     * import bukan operasi idempoten — upload ulang CSV yang sudah diterima
     * bisa menggandakan data scan di Talenta.
     *
     *   - Gagal konek / DNS / connect-timeout -> request belum sampai, aman.
     *   - Timeout saat MENUNGGU jawaban -> mungkin sudah diproses, JANGAN.
     *   - 429 / 502 / 503 -> ditolak sebelum diproses, aman.
     *   - 4xx lain (validasi), 500, 504 -> jangan (salah data / status tak pasti).
     */
    private function isSafeToRetry(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return (bool) preg_match(
                '/cURL error (6|7):|Could not resolve host|Failed to connect|Connection refused|Connection timed out after/i',
                $e->getMessage()
            );
        }

        if ($e instanceof RequestException) {
            return in_array($e->response->status(), [429, 502, 503], true);
        }

        return false;
    }

    /**
     * Susun baris CSV Import Fingerprint dari pasangan [badgeno, Carbon checktime].
     *
     * Delimiter ";" dengan 3 kolom: badgeno;date;checktime
     *   date     -> "YYYY-MM-DD" (mis. 2026-06-26)
     *   checktime-> "HH:mm:ss"   (mis. 15:17:48)
     *
     * @param  iterable<array{0:string,1:\DateTimeInterface}>  $rows
     */
    public function buildFingerprintCsv(iterable $rows): string
    {
        $lines = ['badgeno;date;checktime'];

        foreach ($rows as [$badgeNo, $checkTime]) {
            $lines[] = $badgeNo . ';' . $checkTime->format('Y-m-d') . ';' . $checkTime->format('H:i:s');
        }

        return implode("\n", $lines) . "\n";
    }
}
