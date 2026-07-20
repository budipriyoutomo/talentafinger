<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

        // Log REQUEST ke laravel.log. Authorization HMAC di-redact (mengandung tanda tangan),
        // dan field token tidak ikut dicatat utuh demi keamanan.
        Log::info('Talenta REQUEST import-fingerprint', [
            'url' => $url,
            'method' => 'POST',
            'headers' => ['Date' => $headers['Date'], 'Authorization' => '[redacted]'],
            'user_id' => Setting::value('mekari.fingerprint_user_id'),
            'saved_csv' => storage_path('app/' . $savedPath),
            'csv' => $csvContent,
        ]);

        // Timeout eksplisit: tanpa ini satu upload yang menggantung bisa menahan
        // worker (atau request web) sampai PHP sendiri yang menyerah, dan status
        // log tak pernah sempat ditulis. connectTimeout dibuat pendek karena
        // "server tak terjangkau" tak perlu ditunggu lama; timeout total longgar
        // karena Talenta memproses CSV-nya dulu sebelum menjawab.
        $response = Http::withHeaders($headers)
            ->connectTimeout(10)
            ->timeout(120)
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
