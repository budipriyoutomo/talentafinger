<?php

namespace App\Support;

/**
 * Teks yang ikut masuk ke perintah mesin ZKTeco (ADMS getrequest).
 *
 * Format perintahnya: satu perintah per baris ("C:<id>:DATA UPDATE ...") dan
 * field dipisah TAB ("PIN=1\tName=Budi\tPri=0"). Karena itu karakter kontrol di
 * nilai field (tab, baris baru, dst.) bisa menyisipkan field baru (mis. Pri=14 =
 * admin mesin) atau bahkan perintah baru. Nilai seperti nama karyawan datang dari
 * form registrasi publik, jadi wajib dibersihkan.
 */
final class DeviceText
{
    /** Aturan validasi: tolak karakter kontrol (tab, baris baru, NUL, DEL, ...). */
    public const RULE = 'not_regex:/[\x00-\x1F\x7F]/';

    /** Ganti karakter kontrol dengan spasi, rapikan spasi berlebih di ujung. */
    public static function clean(string|int|null $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $value));
    }
}
