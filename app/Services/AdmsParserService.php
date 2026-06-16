<?php

namespace App\Services;

class AdmsParserService
{
    public function parse(string $rawBody): ?array
    {
        $lines = explode("\n", trim($rawBody));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $parts = explode("\t", $line);

            if (count($parts) < 7) {
                continue;
            }

            if ($parts[0] !== 'ATTLOG') {
                continue;
            }

            $snParts = explode('=', $parts[1]);
            $sn = trim($snParts[1] ?? null);

            $timestamp = trim($parts[2]);
            $bioId = trim($parts[3]);
            $status = trim($parts[4]);
            $verify = trim($parts[5]);
            $workCode = trim($parts[6]);

            if (!$sn || !$timestamp || !$bioId) {
                continue;
            }

            return [
                'sn' => $sn,
                'timestamp' => $timestamp,
                'biometric_id' => $bioId,
                'status' => $status,
                'verify' => $verify,
                'work_code' => $workCode,
            ];
        }

        return null;
    }
}
