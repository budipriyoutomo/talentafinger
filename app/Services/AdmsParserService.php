<?php

namespace App\Services;

class AdmsParserService
{
    /**
     * Parse raw ATTLOG body dari mesin ZKTeco (protokol iClock push).
     *
     * Format tiap baris (tab-separated):
     *   PIN \t DateTime \t Status \t Verify \t WorkCode \t ...
     * Contoh: "1\t2026-06-18 08:03:22\t0\t1\t0"
     *
     * Catatan: SN TIDAK ada di body — dikirim mesin lewat query string.
     * Satu POST bisa berisi banyak baris (banyak record).
     *
     * @return array<int, array{biometric_id:string, timestamp:string, status:string, verify:string, work_code:string, raw:string}>
     */
    public function parse(string $rawBody): array
    {
        $records = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($rawBody));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line);

            // Minimal harus ada PIN + timestamp.
            if (count($parts) < 2) {
                continue;
            }

            $bioId = trim($parts[0]);
            $timestamp = trim($parts[1]);

            if ($bioId === '' || $timestamp === '') {
                continue;
            }

            $records[] = [
                'biometric_id' => $bioId,
                'timestamp'    => $timestamp,
                'status'       => trim($parts[2] ?? ''),
                'verify'       => trim($parts[3] ?? ''),
                'work_code'    => trim($parts[4] ?? ''),
                'raw'          => $line,
            ];
        }

        return $records;
    }

    /**
     * Ekstrak template sidik jari dari body OPERLOG.
     *
     * Baris template (tab-separated), contoh:
     *   "FP PIN=6680626\tFID=5\tSize=1640\tValid=1\tTMP=<base64>"
     *
     * Baris lain (OPLOG ..., USER ...) diabaikan oleh fungsi ini.
     *
     * @return array<int, array{biometric_id:string, fid:int, size:int, valid:int, template:string}>
     */
    public function parseFingerprints(string $rawBody): array
    {
        $records = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($rawBody));

        foreach ($lines as $line) {
            $line = trim($line);

            // Hanya baris yang diawali "FP " yang berisi template sidik jari.
            if (!str_starts_with($line, 'FP ')) {
                continue;
            }

            // Buang prefix "FP ", lalu pisah per field tab → "Key=Value".
            $fields = [];
            foreach (explode("\t", substr($line, 3)) as $pair) {
                $pos = strpos($pair, '=');
                if ($pos === false) {
                    continue;
                }
                $fields[substr($pair, 0, $pos)] = substr($pair, $pos + 1);
            }

            $pin = trim($fields['PIN'] ?? '');
            $tmp = $fields['TMP'] ?? '';

            // PIN + template wajib ada; FID bisa "0" (valid), jadi cek null bukan empty.
            if ($pin === '' || $tmp === '' || !isset($fields['FID'])) {
                continue;
            }

            $records[] = [
                'biometric_id' => $pin,
                'fid'          => (int) $fields['FID'],
                'size'         => (int) ($fields['Size'] ?? strlen($tmp)),
                'valid'        => (int) ($fields['Valid'] ?? 1),
                'template'     => $tmp,
            ];
        }

        return $records;
    }
}
