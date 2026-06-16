<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MekariTalentaService
{
    public function buildSignature(string $method, string $path, string $jsonBody): array
    {
        $date = now()->format('D, d M Y H:i:s T');
        $bodyHash = base64_encode(hash('sha256', $jsonBody, true));

        $stringToSign = "date: {$date}\n{$method} {$path} HTTP/1.1\ndigest: SHA-256={$bodyHash}";
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, config('mekari.client_secret'), true));

        return [
            'Authorization' => "X-Mekari-Signature {$signature}",
            'X-Mekari-Date' => $date,
            'Digest' => "SHA-256={$bodyHash}",
            'Content-Type' => 'application/json',
        ];
    }

    public function sendAttendance(string $talentaEmployeeId, string $timestamp): bool
    {
        $body = json_encode([
            'employee_id' => $talentaEmployeeId,
            'check_time' => $timestamp,
            'status' => 'checkin',
        ]);

        $headers = $this->buildSignature('POST', '/api/v1/attendance', $body);

        try {
            $response = Http::withHeaders($headers)
                ->post(config('mekari.base_url') . '/api/v1/attendance', [
                    'employee_id' => $talentaEmployeeId,
                    'check_time' => $timestamp,
                    'status' => 'checkin',
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            throw $e;
        }
    }
}
