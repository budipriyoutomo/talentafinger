<?php

namespace App\Services;

use App\Models\AttendanceKiosk;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Token QR dinamis untuk absen QR per outlet.
 *
 * Waktu dipotong jadi "window" sepanjang qr_rotate_seconds. Isi QR = URL
 * /absen/qr?k={kiosk}&w={window}&s={HMAC(kiosk|window, secret kiosk)}, jadi:
 *  - QR berganti tiap window, dan screenshot kemarin tak berlaku lagi;
 *  - URL tak bisa ditebak/diubah tanpa secret (yang hanya ada di server);
 *  - rotasi secret (reset aktivasi) langsung membatalkan semua QR lama.
 *
 * Window sebelumnya masih diterima, supaya scan tepat saat QR berganti tidak
 * gagal. Jadi masa berlaku efektif sebuah QR = 1–2 window.
 */
final class QrTokenService
{
    public const ROTATE_MIN = 15;
    public const ROTATE_MAX = 120;
    public const ROTATE_DEFAULT = 30;

    /** Panjang signature (karakter hex). 128 bit cukup dan QR tetap ringkas. */
    private const SIGNATURE_LENGTH = 32;

    public function rotateSeconds(): int
    {
        $seconds = (int) Setting::value('attendance.qr_rotate_seconds', self::ROTATE_DEFAULT);

        if ($seconds <= 0) {
            $seconds = self::ROTATE_DEFAULT;
        }

        return max(self::ROTATE_MIN, min(self::ROTATE_MAX, $seconds));
    }

    public function currentWindow(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? now()->timestamp, $this->rotateSeconds());
    }

    public function sign(AttendanceKiosk $kiosk, int $window): string
    {
        return substr(
            hash_hmac('sha256', "{$kiosk->id}|{$window}", $kiosk->secret),
            0,
            self::SIGNATURE_LENGTH,
        );
    }

    /**
     * URL untuk QR window sekarang + sisa detik sampai QR berganti (dipakai
     * tablet untuk menjadwalkan polling berikutnya).
     *
     * @return array{url: string, window: int, expires_in: int}
     */
    public function urlFor(AttendanceKiosk $kiosk): array
    {
        $now = now()->timestamp;
        $rotate = $this->rotateSeconds();
        $window = intdiv($now, $rotate);

        $query = http_build_query([
            'k' => $kiosk->id,
            'w' => $window,
            's' => $this->sign($kiosk, $window),
        ]);

        return [
            'url' => url('/absen/qr') . '?' . $query,
            'window' => $window,
            'expires_in' => ($window + 1) * $rotate - $now,
        ];
    }

    /**
     * Kiosk pemilik QR bila token sah, null bila tidak. Sengaja tidak
     * membedakan alasan gagal ke pemanggil: karyawan cukup diminta scan ulang.
     *
     * @param  mixed  $window  nilai mentah dari query string
     */
    public function verify(?string $kioskId, $window, ?string $signature): ?AttendanceKiosk
    {
        // Bukan UUID -> tolak sebelum query: Postgres melempar error untuk
        // perbandingan kolom uuid dengan string asal-asalan.
        if (! is_string($kioskId) || ! Str::isUuid($kioskId) || ! is_string($signature) || $signature === '') {
            return null;
        }

        // Window wajib bilangan bulat murni ("123"), bukan "123abc" / "1e3" / array.
        if (is_int($window)) {
            $window = (string) $window;
        }
        if (! is_string($window) || ! ctype_digit($window)) {
            return null;
        }
        $window = (int) $window;

        $current = $this->currentWindow();
        if ($window < $current - 1 || $window > $current) {
            return null;
        }

        $kiosk = AttendanceKiosk::where('id', $kioskId)->where('is_active', true)->first();
        if ($kiosk === null || ! $kiosk->isActivated()) {
            return null;
        }

        return hash_equals($this->sign($kiosk, $window), $signature) ? $kiosk : null;
    }
}
