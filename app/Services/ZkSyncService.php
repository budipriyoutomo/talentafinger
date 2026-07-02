<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Sync sidik jari antar mesin via protokol standalone ZKTeco (TCP 4370),
 * dengan memanggil skrip Python pyzk (scripts/zk_sync.py).
 *
 * ADMS dipakai untuk absensi + sync user; jalur ini KHUSUS template sidik jari
 * karena firmware mesin menolak push template via ADMS (Return=-12).
 */
class ZkSyncService
{
    /** Jalankan satu perintah ke skrip Python, kirim/baca JSON. */
    private function run(array $payload): array
    {
        $python = Setting::value('adms.python_bin', 'python');
        $script = base_path('scripts/zk_sync.py');

        // Di Windows, proses anak butuh SystemRoot agar Winsock (socket) bisa
        // init — tanpa ini Python gagal dgn OSError 10106 saat dipanggil dari
        // server web. Teruskan env penting Windows secara eksplisit.
        $env = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $env = [
                'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
                'SystemDrive' => getenv('SystemDrive') ?: 'C:',
            ];
        }

        // Jejak debug koneksi: ip_hex mengekspos whitespace/karakter tersembunyi
        // yang tak terlihat di log biasa. Template sengaja TIDAK ikut di-log.
        Log::debug('ZkSyncService: eksekusi zk_sync.py', [
            'python' => $python,
            'action' => $payload['action'] ?? null,
            'ip' => $payload['ip'] ?? null,
            'ip_hex' => isset($payload['ip']) ? bin2hex((string) $payload['ip']) : null,
            'port' => $payload['port'] ?? null,
            'timeout' => $payload['timeout'] ?? null,
        ]);

        $result = Process::timeout(120)
            ->env($env)
            ->input(json_encode($payload))
            ->run([$python, $script]);

        if (! $result->successful()) {
            return ['ok' => false, 'error' => 'Proses Python gagal: ' . trim($result->errorOutput() ?: $result->output())];
        }

        $json = json_decode(trim($result->output()), true);

        return is_array($json)
            ? $json
            : ['ok' => false, 'error' => 'Output skrip tidak valid: ' . trim($result->output())];
    }

    private function conn(Machine $m): array
    {
        // trim + cast eksplisit: data bisa masuk tanpa lewat validasi API
        // (seeder/tinker/import), dan whitespace tersembunyi di IP membuat
        // pyzk timeout tanpa pesan yang menjelaskan penyebabnya.
        return [
            'ip' => trim((string) $m->ip_address),
            'port' => (int) ($m->sdk_port ?: Setting::value('adms.sdk_port', 4370)),
        ];
    }

    public function info(Machine $m): array
    {
        return $this->run(['action' => 'info'] + $this->conn($m));
    }

    /**
     * Probe ringan kesehatan jalur TCP 4370 (server -> mesin). Timeout pendek
     * supaya monitor tidak menggantung lama pada mesin yang portnya mati.
     */
    public function ping(Machine $m, int $timeout = 5): array
    {
        return $this->run(['action' => 'ping', 'timeout' => $timeout] + $this->conn($m));
    }

    public function listUsers(Machine $m): array
    {
        return $this->run(['action' => 'list'] + $this->conn($m));
    }

    /** Hapus SEMUA log presensi (records) di mesin. ZK tak mendukung hapus parsial. */
    public function clearAttendance(Machine $m): array
    {
        return $this->run(['action' => 'clear_attendance'] + $this->conn($m));
    }

    public function pull(Machine $source, string $pin): array
    {
        return $this->run(['action' => 'pull', 'pin' => $pin] + $this->conn($source));
    }

    public function push(Machine $target, string $pin, ?string $name, array $templates, int $privilege = 0): array
    {
        return $this->run([
            'action' => 'push',
            'pin' => $pin,
            'name' => $name,
            'privilege' => $privilege,
            'templates' => $templates,
        ] + $this->conn($target));
    }

    public function delete(Machine $target, string $pin): array
    {
        return $this->run(['action' => 'delete', 'pin' => $pin] + $this->conn($target));
    }

    /**
     * Tarik template 1 PIN dari mesin sumber, lalu pasang ke mesin-mesin tujuan.
     *
     * @param Machine[] $targets
     */
    public function syncPin(Machine $source, array $targets, string $pin): array
    {
        $pulled = $this->pull($source, $pin);
        if (! ($pulled['ok'] ?? false)) {
            return ['ok' => false, 'error' => $pulled['error'] ?? 'Gagal menarik template dari mesin sumber'];
        }

        $name = $pulled['user']['name'] ?? null;
        // Ikut bawa hak akses (0=user biasa, !=0=admin/manager/enroller) supaya
        // role di mesin tujuan sama dengan sumber, bukan default jadi user biasa.
        $privilege = (int) ($pulled['user']['privilege'] ?? 0);
        $results = [];

        foreach ($targets as $target) {
            if ($target->id === $source->id) {
                continue; // jangan push balik ke sumber
            }
            $res = $this->push($target, $pin, $name, $pulled['templates'], $privilege);
            $results[] = [
                'machine' => $target->name,
                'ok' => $res['ok'] ?? false,
                'installed' => $res['installed'] ?? 0,
                'error' => $res['error'] ?? null,
            ];
        }

        return [
            'ok' => true,
            'source' => $source->name,
            'pin' => $pin,
            'name' => $name,
            'privilege' => $privilege,
            'pulled_fingers' => count($pulled['templates']),
            'results' => $results,
        ];
    }
}
