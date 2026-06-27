<?php

namespace App\Services;

use App\Models\BiometricTemplate;
use App\Models\DeviceCommand;
use App\Models\EmployeeMapping;
use App\Models\Machine;
use App\Models\Setting;
use Carbon\Carbon;

class DeviceCommandService
{
    /**
     * Encode waktu ke format integer ZKTeco (dipakai SET OPTIONS DateTime=).
     *
     * Rumus standar ZKTeco/iClock:
     *   ((year-2000)*12*31 + (month-1)*31 + (day-1)) * 86400
     *   + hour*3600 + minute*60 + second
     */
    public function encodeTime(Carbon $t): int
    {
        return ((($t->year - 2000) * 12 * 31) + (($t->month - 1) * 31) + ($t->day - 1)) * 86400
            + ($t->hour * 3600) + ($t->minute * 60) + $t->second;
    }

    /**
     * Antrekan perintah set jam ke mesin, memakai waktu sekarang pada
     * timezone perangkat (default Asia/Jakarta / WIB, sesuai TimeZone=7).
     */
    public function queueSyncTime(Machine $machine): DeviceCommand
    {
        $tz = Setting::value('adms.device_timezone', 'Asia/Jakarta');
        $now = Carbon::now($tz);
        $encoded = $this->encodeTime($now);

        return DeviceCommand::create([
            'machine_id' => $machine->id,
            'type' => 'sync_time',
            'command' => "SET OPTIONS DateTime={$encoded}",
            'status' => 'pending',
        ]);
    }

    /**
     * Versi aman untuk auto-sync: lewati bila masih ada perintah sync_time
     * yang belum dieksekusi mesin (status pending/sent), agar antrean tidak
     * menumpuk saat mesin sedang offline atau lambat polling.
     *
     * @return DeviceCommand|null  Perintah baru, atau null bila dilewati.
     */
    public function queueSyncTimeIfAbsent(Machine $machine): ?DeviceCommand
    {
        $hasPending = DeviceCommand::where('machine_id', $machine->id)
            ->where('type', 'sync_time')
            ->whereIn('status', ['pending', 'sent'])
            ->exists();

        if ($hasPending) {
            return null;
        }

        return $this->queueSyncTime($machine);
    }

    /**
     * Antrekan perintah push sidik jari satu karyawan (PIN) ke mesin tujuan.
     *
     * Membuat: 1x DATA UPDATE USERINFO (bikin user-nya) + 1x DATA UPDATE
     * FINGERTMP per jari. Perintah dieksekusi saat mesin polling getrequest.
     * PIN dianggap sama di semua mesin, jadi dipush apa adanya.
     *
     * @return int Jumlah perintah yang diantrekan (0 = tidak ada template).
     */
    public function queuePushFingerprint(Machine $target, string $pin): int
    {
        $templates = BiometricTemplate::where('biometric_id', $pin)
            ->orderBy('fid')
            ->get();

        if ($templates->isEmpty()) {
            return 0;
        }

        // Nama karyawan diambil dari mapping (mesin mana pun) → employee.
        // Mesin butuh USERINFO lebih dulu sebelum FINGERTMP bisa ditempel.
        $name = $this->resolveEmployeeName($pin) ?? $pin;

        $count = 0;

        DeviceCommand::create([
            'machine_id' => $target->id,
            'type' => 'push_user',
            'command' => "DATA UPDATE USERINFO PIN={$pin}\tName={$name}\tPri=0\tPasswd=\tCard=\tGrp=1\tTZ=0000000000000000",
            'status' => 'pending',
        ]);
        $count++;

        foreach ($templates as $tpl) {
            DeviceCommand::create([
                'machine_id' => $target->id,
                'type' => 'push_fp',
                'command' => "DATA UPDATE FINGERTMP PIN={$pin}\tFID={$tpl->fid}\tSize={$tpl->size}\tValid={$tpl->valid}\tTMP={$tpl->template}",
                'status' => 'pending',
            ]);
            $count++;
        }

        return $count;
    }

    private function resolveEmployeeName(string $pin): ?string
    {
        $mapping = EmployeeMapping::with('employee')
            ->where('biometric_id_lokal', $pin)
            ->whereHas('employee')
            ->first();

        return $mapping?->employee?->name;
    }
}
