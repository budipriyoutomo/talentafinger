<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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

        return Http::withHeaders($headers)
            ->attach('file', $csvContent, 'attendance.csv')
            ->post($url, [
                'user_id' => Setting::value('mekari.fingerprint_user_id'),
                'token' => Setting::value('mekari.fingerprint_token'),
            ]);
    }

    /**
     * Susun baris CSV Import Fingerprint dari pasangan [badgeno, Carbon checktime].
     *
     * Format checktime mengikuti contoh Talenta: DD/MM/YYYY H:mm (mis. 17/05/2021 8:30).
     *
     * @param  iterable<array{0:string,1:\DateTimeInterface}>  $rows
     */
    public function buildFingerprintCsv(iterable $rows): string
    {
        $lines = ['badgeno,checktime'];

        foreach ($rows as [$badgeNo, $checkTime]) {
            $lines[] = $badgeNo . ',' . $checkTime->format('d/m/Y G:i');
        }

        return implode("\n", $lines) . "\n";
    }
}
