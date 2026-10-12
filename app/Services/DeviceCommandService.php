<?php

namespace App\Services;

use App\Models\BiometricTemplate;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\Machine;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DeviceCommandService
{
    /** Perintah 'sent' tanpa balasan selama ini (menit) dikirim ulang. */
    public const RESEND_AFTER_MINUTES = 10;

    /** Batas total pengiriman satu perintah sebelum ditandai 'expired'. */
    public const MAX_ATTEMPTS = 3;

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
        return DeviceCommand::create([
            'machine_id' => $machine->id,
            'type' => 'sync_time',
            'command' => $this->syncTimeCommand(),
            'status' => 'pending',
        ]);
    }

    /** Perintah set jam berisi waktu SEKARANG di timezone perangkat. */
    private function syncTimeCommand(): string
    {
        $tz = Setting::value('adms.device_timezone', 'Asia/Jakarta');

        return 'SET OPTIONS DateTime=' . $this->encodeTime(Carbon::now($tz));
    }

    /**
     * Ambil perintah yang harus dikirim ke mesin saat ia polling getrequest,
     * lalu tandai 'sent'.
     *
     * Selain yang 'pending', perintah 'sent' yang tak kunjung dibalas mesin
     * (devicecmd) dalam RESEND_AFTER_MINUTES ikut dikirim ULANG — mesin bisa
     * saja tak menerima balasan getrequest karena koneksi putus. Setelah
     * MAX_ATTEMPTS kali tetap tanpa balasan, perintah ditandai 'expired' supaya
     * tak dikirim selamanya (dan sync_time yang nyangkut tak lagi memblokir
     * auto-sync jam berikutnya).
     *
     * sync_time dihitung ulang dengan jam SAAT DIKIRIM: perintah yang tertahan
     * (mesin offline) atau dikirim ulang tak boleh menyetel jam ke waktu lampau.
     *
     * @return Collection<int, DeviceCommand>
     */
    public function takeForDelivery(Machine $machine): Collection
    {
        $staleBefore = now()->subMinutes(self::RESEND_AFTER_MINUTES);

        DeviceCommand::where('machine_id', $machine->id)
            ->where('status', 'sent')
            ->where('sent_at', '<', $staleBefore)
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->update([
                'status' => 'expired',
                'response' => 'Tak ada balasan dari mesin setelah ' . self::MAX_ATTEMPTS . 'x dikirim.',
            ]);

        $commands = DeviceCommand::where('machine_id', $machine->id)
            ->where(fn ($q) => $q->where('status', 'pending')
                ->orWhere(fn ($q) => $q->where('status', 'sent')->where('sent_at', '<', $staleBefore)))
            ->orderBy('id')
            ->get();

        foreach ($commands as $cmd) {
            $cmd->update([
                'command' => $cmd->type === 'sync_time' ? $this->syncTimeCommand() : $cmd->command,
                'status' => 'sent',
                'sent_at' => now(),
                'attempts' => $cmd->attempts + 1,
            ]);
        }

        return $commands;
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

        // Nama karyawan diambil dari Biometric ID (PIN global) → employee.
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
        return Employee::where('biometric_id', $pin)->value('name');
    }
}
