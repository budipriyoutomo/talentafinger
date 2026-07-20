<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'machine_id',
        'biometric_id_lokal',
        'timestamp',
        'status_sync',
        'payload_raw',
        'error_message',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * Log mengikuti outlet MESIN tempat ia terekam (bukan outlet karyawan),
     * karena log memang peristiwa fisik di outlet tersebut. Konsekuensinya: log
     * dari mesin yang belum ditempatkan hanya terlihat oleh user tanpa batas.
     * $user null = konteks sistem (queue/command), tanpa batas.
     */
    /**
     * Nilai status_sync yang boleh dipakai sebagai filter. Dipakai bersama oleh
     * halaman (tab "Gagal") dan aksi kirim ulang supaya tak ada dua daftar.
     */
    public const STATUSES = ['pending', 'sent', 'failed', 'duplicate'];

    /**
     * Baca filter panel dari request (query string di halaman, body JSON di
     * request kirim ulang) jadi bentuk baku yang dimengerti applyFilters().
     *
     * Status di-whitelist di sini supaya nilai asal-asalan dari browser tak
     * pernah sampai ke query, dan nilai kosong dinormalkan jadi null supaya
     * `when()` melewatinya.
     *
     * @return array<string, string|null>
     */
    public static function filtersFromRequest(\Illuminate\Http\Request $request): array
    {
        $status = $request->input('status');

        return [
            'machine_id' => $request->input('machine_id') ?: null,
            'brand_id' => $request->input('brand_id') ?: null,
            'outlet_id' => $request->input('outlet_id') ?: null,
            'date_from' => $request->input('date_from') ?: null,
            'date_to' => $request->input('date_to') ?: null,
            'status' => in_array($status, self::STATUSES, true) ? $status : null,
        ];
    }

    /**
     * Filter daftar log persis seperti panel filter di halaman Log Absensi.
     *
     * Ditaruh di model, bukan di controller, karena dipakai DUA pihak: tabel yang
     * menampilkan dan tombol yang mengirim ulang. Sebelumnya hanya tabel yang
     * memfilter sementara tombol "Kirim Ulang Semua Gagal" mengambil seluruh log
     * gagal — layar menjanjikan 20 baris, yang terkirim bisa ribuan. Selama kedua
     * pihak memanggil scope ini, keduanya tak bisa lagi berbeda pendapat.
     *
     * Filter dari browser tak perlu diperiksa terhadap scope akses: visibleTo()
     * membatasi lebih dulu, jadi menebak outlet_id orang lain hanya menghasilkan
     * daftar kosong, bukan kebocoran.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeApplyFilters($query, array $filters)
    {
        $status = $filters['status'] ?? null;
        $status = in_array($status, self::STATUSES, true) ? $status : null;

        $outletId = $filters['outlet_id'] ?? null;
        $brandId = $filters['brand_id'] ?? null;

        return $query
            ->when($filters['machine_id'] ?? null, fn ($q, $v) => $q->where('machine_id', $v))
            ->when($status, fn ($q, $v) => $q->where('status_sync', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('timestamp', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('timestamp', '<=', $v))
            // Brand/outlet mengikuti outlet MESIN tempat log terekam — dasar yang
            // sama dengan visibleTo(), jadi tak ada dua definisi "outlet-nya log".
            ->when($outletId, fn ($q, $v) => $q->whereHas('machine', fn ($qq) => $qq->where('outlet_id', $v)))
            ->when($brandId && ! $outletId, fn ($q) => $q->whereHas(
                'machine.outlet',
                fn ($qq) => $qq->where('brand_id', $brandId),
            ));
    }

    public function scopeVisibleTo($query, ?User $user)
    {
        $outletIds = $user?->scopedOutletIds();

        if ($outletIds === null) {
            return $query;
        }

        return $query->whereHas('machine', fn ($q) => $q->whereIn('outlet_id', $outletIds));
    }
}
