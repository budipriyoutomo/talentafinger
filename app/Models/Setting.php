<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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

        return static::castValue($row['value'], $row['type']);
    }

    /** Simpan/timpa satu setting lalu buang cache. */
    public static function put(string $key, $value): void
    {
        static::where('key', $key)->update([
            'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
        ]);
        static::flushCache();
    }

    /**
     * Map key => ['value' => ..., 'type' => ...], di-cache selamanya (dibuang
     * otomatis saat ada save/delete). Sengaja array biasa, bukan koleksi model,
     * agar aman di-serialize ke cache store (mis. database).
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

    protected static function castValue(?string $value, string $type)
    {
        return match ($type) {
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
