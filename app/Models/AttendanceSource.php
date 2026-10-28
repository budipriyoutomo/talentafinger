<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Master sumber absensi: satu baris per AttendanceLog::SOURCES. Menentukan
 * sumber mana yang log-nya dikirim ke Talenta (sync_to_talenta).
 *
 * Baris tak bisa ditambah/dihapus dari UI — kode sumber dihasilkan oleh kode
 * aplikasi (ADMS, absen foto, QR). Yang diatur admin: nama, deskripsi, saklar.
 */
class AttendanceSource extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'sync_to_talenta',
    ];

    protected $casts = [
        'sync_to_talenta' => 'boolean',
    ];

    protected const CACHE_KEY = 'attendance_sources.syncable';

    /**
     * Kode sumber yang boleh dikirim ke Talenta, di-cache (dibuang saat save).
     * Sumber yang belum punya baris dianggap BOLEH, supaya log tak tertahan
     * diam-diam hanya karena migrasi/seed belum lengkap.
     *
     * @return array<string, bool>  code => sync_to_talenta
     */
    public static function syncMap(): array
    {
        return Cache::rememberForever(
            static::CACHE_KEY,
            fn () => static::pluck('sync_to_talenta', 'code')->map(fn ($v) => (bool) $v)->all()
        );
    }

    public static function syncsToTalenta(?string $code): bool
    {
        return static::syncMap()[$code ?? AttendanceLog::SOURCE_MACHINE] ?? true;
    }

    /**
     * Status awal log yang SIAP dikirim (log mesin baru, absen manual yang
     * disetujui): 'pending' bila sumbernya di-sync, 'skipped' bila tidak.
     */
    public static function readyStatus(?string $code): string
    {
        return static::syncsToTalenta($code) ? 'pending' : 'skipped';
    }

    public static function flushCache(): void
    {
        Cache::forget(static::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }
}
