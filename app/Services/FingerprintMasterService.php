<?php

namespace App\Services;

use App\Models\BiometricTemplate;
use App\Models\Employee;
use App\Models\EmployeeMapping;
use App\Models\Machine;

/**
 * Master sidik jari berbasis KARYAWAN (Opsi A).
 *
 * Alur:
 *   1. TARIK  : pull template 1 karyawan dari mesin (via TCP 4370 / pyzk) -> simpan ke DB
 *               (biometric_templates.employee_id). DB = sumber kebenaran.
 *   2. SEBAR  : rakit template karyawan dari DB -> push ke mesin tujuan (TCP 4370).
 *
 * PIN di mesin TIDAK disimpan di template; diambil per mesin dari
 * employee_mappings.biometric_id_lokal (1 karyawan bisa beda PIN tiap mesin).
 */
class FingerprintMasterService
{
    public function __construct(private ZkSyncService $zk) {}

    /**
     * PIN karyawan di sebuah mesin. Utamakan Biometric ID master (employees.biometric_id);
     * bila kosong, fallback ke mapping per-mesin (employee_mappings.biometric_id_lokal).
     * Null bila keduanya tak ada.
     */
    public function resolvePin(Employee $employee, Machine $machine): ?string
    {
        if (! empty($employee->biometric_id)) {
            return (string) $employee->biometric_id;
        }

        return EmployeeMapping::where('employee_id', $employee->id)
            ->where('machine_id', $machine->id)
            ->value('biometric_id_lokal');
    }

    /**
     * TARIK: ambil template karyawan dari mesin sumber, simpan/upsert ke DB.
     * Mengganti seluruh template karyawan yang ada (sumber = kebenaran terbaru).
     */
    public function captureFromMachine(Employee $employee, Machine $source): array
    {
        if (! $source->ip_address) {
            return ['ok' => false, 'error' => "Mesin sumber {$source->name} belum punya IP."];
        }

        $pin = $this->resolvePin($employee, $source);
        if (! $pin) {
            return ['ok' => false, 'error' => "Karyawan belum punya Biometric ID (atau mapping) untuk mesin {$source->name}."];
        }

        $pulled = $this->zk->pull($source, $pin);
        if (! ($pulled['ok'] ?? false)) {
            return ['ok' => false, 'error' => $pulled['error'] ?? 'Gagal menarik template dari mesin sumber.'];
        }

        $templates = $pulled['templates'] ?? [];
        if (empty($templates)) {
            return ['ok' => false, 'error' => "PIN {$pin} tidak punya template valid di {$source->name}."];
        }

        // Bawa hak akses dari mesin ke master (agar ikut tersebar nanti).
        $privilege = (int) ($pulled['user']['privilege'] ?? 0);
        if ($privilege !== (int) $employee->device_privilege) {
            $employee->update(['device_privilege' => $privilege]);
        }

        // Tulis ulang seluruh template karyawan ini agar sinkron dengan sumber.
        BiometricTemplate::where('employee_id', $employee->id)->delete();

        foreach ($templates as $t) {
            $tmp = $t['tmp']; // base64
            BiometricTemplate::create([
                'employee_id' => $employee->id,
                'biometric_id' => $pin, // catatan PIN asal
                'fid' => (int) $t['fid'],
                'valid' => (int) ($t['valid'] ?? 1),
                'size' => strlen(base64_decode($tmp)),
                'template' => $tmp,
                'source_machine_id' => $source->id,
                'enrolled_at' => now(),
            ]);
        }

        return [
            'ok' => true,
            'employee_id' => $employee->id,
            'source' => $source->name,
            'pin' => $pin,
            'fingers' => count($templates),
            'privilege' => $privilege,
        ];
    }

    /**
     * SEBAR: rakit template karyawan dari DB, push ke satu mesin tujuan via TCP 4370.
     */
    public function distributeToMachine(Employee $employee, Machine $target): array
    {
        if (! $target->ip_address) {
            return ['ok' => false, 'machine' => $target->name, 'error' => "Mesin {$target->name} belum punya IP."];
        }

        $pin = $this->resolvePin($employee, $target);
        if (! $pin) {
            return ['ok' => false, 'machine' => $target->name, 'error' => "Karyawan belum punya Biometric ID (atau mapping) untuk mesin {$target->name}."];
        }

        $rows = BiometricTemplate::where('employee_id', $employee->id)->orderBy('fid')->get();
        if ($rows->isEmpty()) {
            return ['ok' => false, 'machine' => $target->name, 'error' => 'Tidak ada template tersimpan di DB untuk karyawan ini.'];
        }

        $templates = $rows->map(fn ($r) => [
            'fid' => (int) $r->fid,
            'valid' => (int) $r->valid,
            'tmp' => $r->template, // base64 apa adanya dari DB
        ])->all();

        $res = $this->zk->push($target, $pin, $employee->name, $templates, (int) $employee->device_privilege);

        return [
            'ok' => $res['ok'] ?? false,
            'machine' => $target->name,
            'pin' => $pin,
            'installed' => $res['installed'] ?? 0,
            'privilege' => (int) $employee->device_privilege,
            'error' => $res['error'] ?? null,
        ];
    }
}
