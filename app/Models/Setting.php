<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'group',
        'type',
        'label',
        'description',
    ];

    protected const CACHE_KEY = 'app_settings';

    /**
     * Nilai setting (sudah di-cast sesuai type). Bila baris belum ada di DB,
     * fallback ke config() karena key memakai notasi dot yang sama (mis.
     * "mekari.client_id"). Dengan begitu .env tetap jadi default yang aman.
     */
    public static function value(string $key, $default = null)
    {
        $row = static::cached()[$key] ?? null;

        // Belum ada baris, atau nilainya kosong -> fallback ke config()/default.
        if ($row === null || $row['value'] === null || $row['value'] === '') {
            return config($key, $default);
        }

        return static::castValue($row['value'], $row['type'], $key);
    }

    /**
     * Simpan/timpa satu setting lalu buang cache. Nilai bertipe 'password'
     * dienkripsi dengan APP_KEY sebelum ditulis. Query update biasa, jadi
     * mutator/cast model memang tidak akan terpanggil di sini.
     */
    public static function put(string $key, $value): void
    {
        $type = static::where('key', $key)->value('type');

        // Baris belum ada = SettingSeeder belum dijalankan. Jangan gagal diam-diam:
        // nilainya tak tersimpan dan fitur terlihat "mati" tanpa sebab yang jelas.
        if ($type === null) {
            throw new \RuntimeException(
                "Setting '{$key}' belum ada di tabel settings. Jalankan: php artisan db:seed --class=SettingSeeder --force"
            );
        }

        $stored = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        static::where('key', $key)->update([
            'value' => $type === 'password' ? static::encryptSecret($stored) : $stored,
        ]);

        static::flushCache();
    }

    /**
     * Enkripsi secret untuk disimpan. Kosong tetap kosong, supaya value()
     * tetap fallback ke .env dan is_set di halaman Settings tidak keliru.
     */
    public static function encryptSecret(?string $plain): ?string
    {
        return blank($plain) ? $plain : Crypt::encryptString($plain);
    }

    /**
     * Dekripsi secret. Toleran: nilai yang belum terenkripsi (plaintext lama
     * sebelum migrasi, atau dibuat langsung oleh test) dikembalikan apa adanya.
     * Nilainya sendiri TIDAK pernah ditulis ke log.
     */
    public static function decryptSecret(?string $stored, ?string $key = null): ?string
    {
        if (blank($stored)) {
            return $stored;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::warning('Setting password tidak terenkripsi atau APP_KEY berbeda; nilai dipakai apa adanya.', [
                'key' => $key,
            ]);

            return $stored;
        }
    }

    /** Nilai asli (terdekripsi bila 'password') dari satu baris setting. */
    public static function plainValue(self $setting): ?string
    {
        return $setting->type === 'password'
            ? static::decryptSecret($setting->value, $setting->key)
            : $setting->value;
    }

    /**
     * Map key => ['value' => ..., 'type' => ...], di-cache selamanya (dibuang
     * otomatis saat ada save/delete). Sengaja array biasa, bukan koleksi model,
     * agar aman di-serialize ke cache store (mis. database). Secret tetap
     * berupa ciphertext di cache; didekripsi saat dibaca lewat value().
     */
    public static function cached(): array
    {
        return Cache::rememberForever(
            static::CACHE_KEY,
            fn () => static::all()
                ->mapWithKeys(fn ($s) => [$s->key => ['value' => $s->value, 'type' => $s->type]])
                ->all()
        );
    }

    public static function flushCache(): void
    {
        Cache::forget(static::CACHE_KEY);
    }

    protected static function castValue(?string $value, string $type, ?string $key = null)
    {
        return match ($type) {
            'password' => static::decryptSecret($value, $key),
            'number' => is_numeric($value) ? $value + 0 : $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }
}
