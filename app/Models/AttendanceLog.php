<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    /** Log dari mesin fingerprint (ADMS). */
    public const SOURCE_MACHINE = 'machine';

    /** Absen manual karyawan pakai foto + geolokasi (lihat ManualAttendanceController). */
    public const SOURCE_MANUAL_PHOTO = 'manual_photo';

    /** Absen karyawan via scan QR di tablet outlet + selfie + geolokasi (lihat QrTokenService). */
    public const SOURCE_QR = 'qr';

    public const SOURCES = [self::SOURCE_MACHINE, self::SOURCE_MANUAL_PHOTO, self::SOURCE_QR];

    /** Sumber yang dikirim KARYAWAN sendiri (punya foto, butuh review). */
    public const EMPLOYEE_SOURCES = [self::SOURCE_MANUAL_PHOTO, self::SOURCE_QR];

    protected $fillable = [
        'machine_id',
        'source',
        'employee_id',
        'outlet_id',
        'kiosk_id',
        'biometric_id_lokal',
        'timestamp',
        'status_sync',
        'payload_raw',
        'error_message',
        'photo_path',
        'latitude',
        'longitude',
        'accuracy_meters',
        'distance_meters',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $attributes = [
        'source' => self::SOURCE_MACHINE,
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'reviewed_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'accuracy_meters' => 'integer',
        'distance_meters' => 'integer',
    ];

    /** Path foto di disk privat — jangan pernah ikut terkirim apa adanya ke browser. */
    protected $hidden = ['photo_path'];

    /**
     * Serialisasi JSON (respons API) memakai jam dinding apa adanya, bukan ISO
     * UTC: `timestamp` adalah jam lokal perangkat, dan konversi UTC membuat
     * browser menggesernya lagi sesuai zona waktunya sendiri.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    /** Karyawan pemilik absen manual (log mesin: null, karyawan di-resolve via PIN). */
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    /** Outlet tempat absen manual dilakukan (log mesin: null, pakai outlet mesinnya). */
    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    /** Tablet tempat QR di-scan (hanya log source='qr'). */
    public function kiosk()
    {
        return $this->belongsTo(AttendanceKiosk::class, 'kiosk_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Absen yang dikirim karyawan sendiri: foto atau QR, bukan mesin. Log
     * seperti ini punya foto, outlet_id sendiri, dan wajib direview.
     */
    public function isManual(): bool
    {
        return in_array($this->source, self::EMPLOYEE_SOURCES, true);
    }

    /**
     * Apakah karyawan baru saja mengirim absen sendiri (foto ATAU QR) dalam
     * $minutes menit terakhir. Lintas sumber supaya absen foto lalu absen QR
     * beberapa detik kemudian tetap terhitung kirim ganda. Yang ditolak tak
     * dihitung: karyawan boleh langsung mencoba lagi.
     */
    public static function recentEmployeeSubmission(string $employeeId, int $minutes): bool
    {
        return static::where('employee_id', $employeeId)
            ->whereIn('source', self::EMPLOYEE_SOURCES)
            ->where('status_sync', '!=', 'rejected')
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->exists();
    }

    /**
     * Outlet "pemilik" log untuk keperluan scope akses: outlet MESIN untuk log
     * mesin, outlet tempat absen untuk log manual.
     */
    public function owningOutletId(): ?string
    {
        return $this->isManual() ? $this->outlet_id : $this->machine?->outlet_id;
    }

    /**
     * Nilai status_sync yang boleh dipakai sebagai filter. Dipakai bersama oleh
     * halaman (tab "Gagal") dan aksi kirim ulang supaya tak ada dua daftar.
     */
    public const STATUSES = ['pending_review', 'pending', 'sent', 'failed', 'rejected', 'duplicate', 'skipped'];

    /**
     * Status yang TIDAK boleh dikirim ke Talenta lewat jalur mana pun:
     * absen manual yang belum disetujui supervisor, yang ditolak, atau yang
     * sumbernya dimatikan sync-nya di master sumber (AttendanceSource).
     */
    public const UNSENDABLE_STATUSES = ['pending_review', 'rejected', 'skipped'];

    /**
     * Status yang boleh dikirim ULANG ke Talenta lewat aksi kirim ulang.
     *
     * 'failed' jelas: percobaan sebelumnya memang gagal. 'sent' sengaja ikut,
     * karena "sudah terkirim" hanya berarti Talenta menerima request kita saat
     * itu — kalau datanya hilang/berubah di sisi Talenta, satu-satunya jalan
     * memperbaiki adalah mengirimnya lagi. 'pending' tidak di sini (belum pernah
     * dikirim, itu urusan "Kirim Semua Pending") dan 'duplicate' juga tidak
     * (memang sengaja tidak dikirim).
     */
    public const RESENDABLE_STATUSES = ['failed', 'sent'];

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
        $source = $request->input('source');

        return [
            'machine_id' => $request->input('machine_id') ?: null,
            'brand_id' => $request->input('brand_id') ?: null,
            'outlet_id' => $request->input('outlet_id') ?: null,
            'date_from' => $request->input('date_from') ?: null,
            'date_to' => $request->input('date_to') ?: null,
            'status' => in_array($status, self::STATUSES, true) ? $status : null,
            'source' => in_array($source, self::SOURCES, true) ? $source : null,
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
        $source = $filters['source'] ?? null;
        $source = in_array($source, self::SOURCES, true) ? $source : null;

        return $query
            ->when($filters['machine_id'] ?? null, fn ($q, $v) => $q->where('machine_id', $v))
            ->when($status, fn ($q, $v) => $q->where('status_sync', $v))
            ->when($source, fn ($q, $v) => $q->where('source', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('timestamp', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('timestamp', '<=', $v))
            // Brand/outlet mengikuti outlet MESIN (log mesin) atau outlet tempat
            // absen (log manual) — dasar yang sama dengan visibleTo(), jadi tak
            // ada dua definisi "outlet-nya log".
            ->when($outletId, fn ($q, $v) => $q->where(fn ($qq) => $qq
                ->whereHas('machine', fn ($m) => $m->where('outlet_id', $v))
                ->orWhere('outlet_id', $v)))
            ->when($brandId && ! $outletId, fn ($q) => $q->where(fn ($qq) => $qq
                ->whereHas('machine.outlet', fn ($o) => $o->where('brand_id', $brandId))
                ->orWhereHas('outlet', fn ($o) => $o->where('brand_id', $brandId))));
    }

    /**
     * Log mesin mengikuti outlet MESIN tempat ia terekam (bukan outlet karyawan),
     * karena log memang peristiwa fisik di outlet tersebut. Log manual mengikuti
     * outlet tempat absen (kolom outlet_id). Log dari mesin yang belum
     * ditempatkan hanya terlihat oleh user tanpa batas.
     * $user null = konteks sistem (queue/command), tanpa batas.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        $outletIds = $user?->scopedOutletIds();

        if ($outletIds === null) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->whereHas('machine', fn ($m) => $m->whereIn('outlet_id', $outletIds))
            ->orWhere(fn ($m) => $m->whereNull('machine_id')->whereIn('outlet_id', $outletIds)));
    }
}
