<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Tablet di outlet yang menampilkan QR absen dinamis (lihat QrTokenService).
 *
 * Tiga rahasia, tiga perlakuan berbeda:
 *  - secret               : kunci HMAC QR. Harus bisa dibaca lagi -> cast 'encrypted'.
 *  - activation_code_hash : kode aktivasi sekali pakai, cukup dicocokkan -> sha256.
 *  - device_token_hash    : token tablet jangka panjang, cukup dicocokkan -> sha256.
 */
class AttendanceKiosk extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    /** Tablet dianggap online bila polling /kiosk/state dalam rentang ini. */
    public const ONLINE_MINUTES = 2;

    /** Masa berlaku kode aktivasi sejak dibuat admin. */
    public const ACTIVATION_MINUTES = 30;

    public const ACTIVATION_CODE_LENGTH = 8;

    /** Tanpa karakter yang mudah tertukar saat diketik (0/O, 1/I/L). */
    public const ACTIVATION_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected $fillable = [
        'outlet_id',
        'name',
        'is_active',
    ];

    protected $casts = [
        'secret' => 'encrypted',
        'is_active' => 'boolean',
        'activation_expires_at' => 'datetime',
        'activated_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    protected $hidden = ['secret', 'activation_code_hash', 'device_token_hash'];

    protected static function booted(): void
    {
        static::creating(function (AttendanceKiosk $kiosk) {
            if (empty($kiosk->secret)) {
                $kiosk->secret = Str::random(64);
            }
        });
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function logs()
    {
        return $this->hasMany(AttendanceLog::class, 'kiosk_id');
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gte(now()->subMinutes(self::ONLINE_MINUTES));
    }

    public function isActivated(): bool
    {
        return $this->device_token_hash !== null;
    }

    /**
     * Buat kode aktivasi baru (sekali pakai, berlaku ACTIVATION_MINUTES).
     * Yang disimpan hanya hash-nya; plaintext dikembalikan untuk ditampilkan
     * SEKALI ke admin. Belum di-save — pemanggil yang menyimpan.
     */
    public function issueActivationCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::ACTIVATION_CODE_LENGTH; $i++) {
            $code .= self::ACTIVATION_ALPHABET[random_int(0, strlen(self::ACTIVATION_ALPHABET) - 1)];
        }

        $this->activation_code_hash = self::hashActivationCode($code);
        $this->activation_expires_at = now()->addMinutes(self::ACTIVATION_MINUTES);

        return $code;
    }

    /**
     * Tukar kode aktivasi dengan token device jangka panjang. Kode langsung
     * hangus. Mengembalikan token plaintext (hanya tablet yang menyimpannya).
     */
    public function activate(): string
    {
        $token = Str::random(64);

        $this->device_token_hash = hash('sha256', $token);
        $this->activated_at = now();
        $this->activation_code_hash = null;
        $this->activation_expires_at = null;
        $this->save();

        return $token;
    }

    /**
     * Reset aktivasi (tablet hilang / token bocor): cabut token device dan
     * rotasi secret supaya QR yang mungkin sudah difoto ikut tak berlaku.
     * Mengembalikan kode aktivasi baru untuk tablet pengganti.
     */
    public function resetActivation(): string
    {
        $this->device_token_hash = null;
        $this->activated_at = null;
        $this->last_seen_at = null;
        $this->last_seen_ip = null;
        $this->secret = Str::random(64);

        $code = $this->issueActivationCode();
        $this->save();

        return $code;
    }

    /** Kode diketik di tablet: abaikan huruf kecil, spasi, dan tanda hubung. */
    public static function hashActivationCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[\s-]+/', '', $code)));
    }

    /**
     * Kiosk aktif dengan kode aktivasi ini yang belum kedaluwarsa. Barisnya
     * dikunci (FOR UPDATE), jadi panggil di dalam transaksi: dua tablet yang
     * memasukkan kode yang sama bersamaan -> yang kedua menunggu, lalu tak
     * menemukan kode lagi (sudah dihapus activate()).
     */
    public static function findByActivationCode(?string $code): ?self
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return static::where('activation_code_hash', self::hashActivationCode($code))
            ->where('activation_expires_at', '>', now())
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
    }

    /** Kiosk yang boleh dilihat user (ikut outlet). $user null = konteks sistem, tanpa batas. */
    public function scopeVisibleTo($query, ?User $user)
    {
        $outletIds = $user?->scopedOutletIds();

        if ($outletIds === null) {
            return $query;
        }

        return $query->whereIn('outlet_id', $outletIds);
    }

    /** Kiosk aktif pemilik token device (plaintext dari header tablet). */
    public static function findByDeviceToken(?string $plain): ?self
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        return static::where('device_token_hash', hash('sha256', $plain))
            ->where('is_active', true)
            ->first();
    }
}
