<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Deteksi titip absen (R4): tandai absen foto / QR dari perangkat yang tak
 * biasa. Prinsipnya MEMBERI TANDA, BUKAN MEMBLOKIR — keputusan tetap pada
 * supervisor di layar review.
 *
 * Identitas perangkat = UUID acak di cookie `att_device` (httpOnly, Lax,
 * berumur panjang). Database hanya menyimpan SHA-256-nya. Tidak ada
 * fingerprinting browser, IMEI, atau data perangkat lain.
 *
 * Batasan yang diterima: hapus cookie / incognito / ganti browser terbaca
 * sebagai perangkat baru, jadi flag tidak otomatis berarti curang.
 */
class AttendanceDeviceService
{
    public const COOKIE = 'att_device';

    public const FLAG_NEW_DEVICE = 'new_device';

    public const FLAG_SHARED_DEVICE = 'shared_device';

    /** Jendela "perangkat ini dipakai karyawan lain". */
    public const SHARED_WINDOW_DAYS = 30;

    /** Umur cookie: 5 tahun (menit). */
    private const COOKIE_MINUTES = 60 * 24 * 365 * 5;

    public static function enabled(): bool
    {
        return (bool) Setting::value('attendance.device_check', true);
    }

    /**
     * Catat perangkat absen ini untuk $employee dan kembalikan flag-nya.
     * Panggil hanya saat absen benar-benar akan disimpan (lolos validasi).
     *
     * @return string[] daftar flag; kosong = tak ada yang perlu diperhatikan
     */
    public function check(Request $request, Employee $employee): array
    {
        if (! self::enabled()) {
            return [];
        }

        $token = $this->token($request);
        $hash = hash('sha256', $token);
        $now = now();

        $flags = [];

        $device = EmployeeDevice::where('employee_id', $employee->id)
            ->where('device_hash', $hash)
            ->first();
        $knownActive = $device !== null && $device->revoked_at === null;

        // Perangkat pertama karyawan didaftarkan tanpa flag. Flag hanya bila
        // karyawan SUDAH punya perangkat aktif lain dan yang ini belum dikenal
        // (atau pernah di-reset).
        if (! $knownActive) {
            $hasOtherActive = EmployeeDevice::where('employee_id', $employee->id)
                ->where('device_hash', '!=', $hash)
                ->active()
                ->exists();

            if ($hasOtherActive) {
                $flags[] = self::FLAG_NEW_DEVICE;
            }
        }

        $sharedWithOthers = EmployeeDevice::where('device_hash', $hash)
            ->where('employee_id', '!=', $employee->id)
            ->where('last_seen_at', '>=', $now->copy()->subDays(self::SHARED_WINDOW_DAYS))
            ->exists();

        if ($sharedWithOthers) {
            $flags[] = self::FLAG_SHARED_DEVICE;
        }

        $userAgent = Str::limit((string) $request->userAgent(), 252, '');

        if ($device === null) {
            EmployeeDevice::create([
                'employee_id' => $employee->id,
                'device_hash' => $hash,
                'user_agent' => $userAgent ?: null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        } else {
            // Perangkat yang pernah di-reset lalu dipakai lagi kembali aktif.
            $device->update([
                'user_agent' => $userAgent ?: $device->user_agent,
                'last_seen_at' => $now,
                'revoked_at' => null,
            ]);
        }

        return $flags;
    }

    /** Nilai cookie perangkat; buat baru (dan antrekan cookie-nya) bila belum ada. */
    private function token(Request $request): string
    {
        $token = $request->cookie(self::COOKIE);

        if (is_string($token) && Str::isUuid($token)) {
            return $token;
        }

        $token = (string) Str::uuid();

        Cookie::queue(Cookie::make(
            self::COOKIE,
            $token,
            self::COOKIE_MINUTES,
            path: '/',
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $token;
    }
}
