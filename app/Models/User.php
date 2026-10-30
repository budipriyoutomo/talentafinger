<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'must_change_password', 'role', 'is_active', 'last_login_at', 'last_login_ip', 'employee_id', 'registration_status', 'registered_at', 'registration_outlet_id', 'registration_nik'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, Prunable;

    /** Status registrasi mandiri karyawan (null = akun biasa / sudah selesai). */
    public const REG_UNVERIFIED = 'unverified';

    public const REG_PENDING = 'pending';

    public const REG_REJECTED = 'rejected';

    /** Pendaftaran yang tak pernah diverifikasi / ditolak dibuang setelah ini. */
    public const UNVERIFIED_RETENTION_DAYS = 7;

    public const REJECTED_RETENTION_DAYS = 30;

    /**
     * Samakan dengan default kolom `role` di DB. Tanpa ini, user yang dibuat
     * tanpa menyebut role punya role NULL di memori (default DB baru terpakai
     * setelah di-reload), sehingga semua pemeriksaan izin menolaknya.
     */
    protected $attributes = [
        'role' => 'operator',
        'is_active' => true,
    ];

    /**
     * Daftar outlet_id efektif user ini, di-hitung sekali per instance karena
     * dipakai berulang di banyak query dalam satu request. null = tanpa batas.
     */
    private ?array $resolvedScope = null;

    /**
     * Permission role user ini, di-memo per instance bersama key role-nya
     * supaya ganti role pada instance yang sama tetap terbaca benar.
     *
     * @var array{0: string|null, 1: string[]}|null
     */
    private ?array $resolvedPermissions = null;

    /** Admin: lolos semua permission & scope (Gate::before). */
    public function isAdmin(): bool
    {
        return $this->role === Role::ADMIN;
    }

    /** Akun KHUSUS karyawan (role employee): hanya absen, bukan pengguna admin panel. */
    public function isEmployee(): bool
    {
        return $this->role === Role::EMPLOYEE;
    }

    /**
     * Akun ini ditautkan ke data karyawan, apa pun role-nya. User role lain
     * (mis. manager) yang juga karyawan ikut memegang izin role Karyawan
     * (absen foto / QR) — lihat permissions().
     */
    public function isLinkedEmployee(): bool
    {
        return $this->employee_id !== null;
    }

    /**
     * Halaman "beranda" user: dashboard untuk pengguna panel; untuk akun
     * karyawan /absen (absen foto), kecuali absen foto tak bisa ia pakai
     * sementara absen QR aktif -> langsung ke /absen/qr.
     */
    public function homePath(): string
    {
        if (! $this->isEmployee()) {
            return '/dashboard';
        }

        $photoUsable = Setting::value('attendance.manual_photo_enabled')
            && $this->employee?->allow_manual_attendance;

        return ! $photoUsable && Setting::value('attendance.qr_enabled') ? '/absen/qr' : '/absen';
    }

    /** Role (baris tabel roles) milik user ini. */
    public function roleModel()
    {
        return $this->belongsTo(Role::class, 'role', 'key');
    }

    /**
     * Permission role user + (bila ditautkan ke karyawan) permission role
     * Karyawan. Jadi role Karyawan "digabung" dengan role utama lewat tautan
     * karyawan, bukan lewat banyak role per user.
     *
     * @return string[]
     */
    public function permissions(): array
    {
        $memoKey = $this->role . '|' . ($this->employee_id ?? '');

        if ($this->resolvedPermissions === null || $this->resolvedPermissions[0] !== $memoKey) {
            $permissions = Role::permissionsFor($this->role);

            if ($this->isLinkedEmployee() && ! $this->isEmployee()) {
                $permissions = array_values(array_unique(
                    array_merge($permissions, Role::permissionsFor(Role::EMPLOYEE))
                ));
            }

            $this->resolvedPermissions = [$memoKey, $permissions];
        }

        return $this->resolvedPermissions[1];
    }

    /**
     * Data karyawan yang ditautkan ke akun ini. Wajib untuk role `employee`,
     * opsional untuk role lain (user yang juga karyawan & ikut absen).
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    /** Penugasan user ke Company / Brand / Outlet (lihat model UserScope). */
    public function dataScopes()
    {
        return $this->hasMany(UserScope::class);
    }

    /**
     * Boleh melakukan aksi ini? Sumbu PERTAMA dari izin (verb), lepas dari
     * outlet mana datanya. Peta role -> permission ada di tabel roles (Role).
     */
    public function hasPermission(string $permission): bool
    {
        $granted = $this->permissions();

        return in_array('*', $granted, true)
            || in_array($permission, $granted, true);
    }

    /**
     * Bebas dari batasan outlet? Admin, atau role global yang memegang
     * permission `data.all` (mis. HR Pusat).
     */
    public function hasFullDataAccess(): bool
    {
        return $this->isAdmin() || $this->hasPermission('data.all');
    }

    /**
     * Sumbu KEDUA dari izin (baris): outlet mana saja yang boleh disentuh user.
     *
     *   null        = tanpa batas (admin) — jangan filter apa pun.
     *   array []    = tidak ada outlet sama sekali — user tak lihat data apa pun.
     *   array [...] = hanya outlet ini.
     *
     * Penugasan level company/brand diturunkan ke outlet-outlet di bawahnya.
     */
    public function scopedOutletIds(): ?array
    {
        if ($this->hasFullDataAccess()) {
            return null;
        }

        return $this->resolvedScope ??= $this->resolveScopedOutletIds();
    }

    private function resolveScopedOutletIds(): array
    {
        $byType = $this->dataScopes()->get()->groupBy('scope_type');
        $idsOf = fn (string $type) => $byType->get($type)?->pluck('scope_id')->all() ?? [];

        return static::expandScopeToOutlets($idsOf('company'), $idsOf('brand'), $idsOf('outlet'));
    }

    /**
     * Penugasan langsung (bukan turunan) per level, mis. untuk memeriksa apa
     * yang boleh diteruskan pengelola user ke orang lain.
     *
     * @return array{company: string[], brand: string[], outlet: string[]}
     */
    public function directScopeIds(): array
    {
        $byType = $this->dataScopes()->get()->groupBy('scope_type');

        return collect(UserScope::TYPES)
            ->mapWithKeys(fn ($t) => [$t => $byType->get($t)?->pluck('scope_id')->all() ?? []])
            ->all();
    }

    /**
     * Turunkan penugasan company/brand/outlet menjadi daftar outlet_id efektif.
     * Company -> semua brand-nya -> semua outlet-nya, digabung outlet langsung.
     */
    public static function expandScopeToOutlets(array $companyIds, array $brandIds, array $outletIds): array
    {
        // Turunkan company -> outlet dan brand -> outlet dalam dua query, lalu
        // gabung dengan outlet yang ditugaskan langsung.
        if ($companyIds) {
            $brandIds = array_merge(
                $brandIds,
                Brand::whereIn('company_id', $companyIds)->pluck('id')->all(),
            );
        }

        if ($brandIds) {
            $outletIds = array_merge(
                $outletIds,
                Outlet::whereIn('brand_id', $brandIds)->pluck('id')->all(),
            );
        }

        return array_values(array_unique($outletIds));
    }

    /**
     * Outlet ini boleh disentuh user? Outlet null (mis. mesin yang belum
     * ditempatkan) hanya boleh disentuh user tanpa batas — kalau tidak, data
     * tak bertuan akan bocor ke semua orang.
     */
    public function canAccessOutlet(?string $outletId): bool
    {
        $allowed = $this->scopedOutletIds();

        if ($allowed === null) {
            return true;
        }

        return $outletId !== null && in_array($outletId, $allowed, true);
    }

    /** Penyetuju / penolak pendaftaran akun ini. */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Outlet yang dipilih pendaftar di /register. */
    public function registrationOutlet()
    {
        return $this->belongsTo(Outlet::class, 'registration_outlet_id');
    }

    /**
     * Pendaftaran disetujui -> karyawannya ditautkan ke outlet pilihannya
     * (tautan outlet lain tak disentuh), supaya scope & absen QR outlet itu
     * langsung berlaku.
     */
    public function linkRegistrationOutlet(): void
    {
        if ($this->registration_outlet_id && $this->employee) {
            $this->employee->outlets()->syncWithoutDetaching([$this->registration_outlet_id]);
        }
    }

    /** Pendaftar tanpa data karyawan (karyawannya baru dibuat saat disetujui)? */
    public function isNewEmployeeRegistration(): bool
    {
        return $this->employee_id === null && filled($this->registration_nik);
    }

    /** Karyawan yang sudah terdata dengan NIK isian pendaftar (akan ditautkan saat disetujui). */
    public function registrationMatchedEmployee(): ?Employee
    {
        return $this->isNewEmployeeRegistration()
            ? Employee::whereRaw('TRIM(employee_code) = ?', [$this->registration_nik])->first()
            : null;
    }

    /**
     * Alasan pendaftaran tanpa karyawan ini belum bisa disetujui (null = bisa):
     * NIK isiannya milik karyawan yang sudah punya akun lain, atau yang nonaktif.
     */
    public function registrationApprovalBlocker(): ?string
    {
        $employee = $this->registrationMatchedEmployee();

        return match (true) {
            ! $employee => null,
            static::where('employee_id', $employee->id)->whereKeyNot($this->id)->exists() => "NIK {$this->registration_nik} milik karyawan {$employee->name} yang sudah punya akun. Tolak pendaftaran ini.",
            ! $employee->is_active => "NIK {$this->registration_nik} milik karyawan nonaktif {$employee->name}. Aktifkan karyawannya dulu, atau tolak pendaftaran ini.",
            default => null,
        };
    }

    /**
     * Pendaftaran tanpa data karyawan disetujui -> tautkan ke karyawan ber-NIK
     * sama bila sudah terdata, atau buat karyawan baru dari isian form (nama,
     * NIK, email). Cek registrationApprovalBlocker() dulu; panggil di dalam
     * transaksi, sebelum linkRegistrationOutlet().
     */
    public function createRegistrationEmployee(): void
    {
        if (! $this->isNewEmployeeRegistration()) {
            return;
        }

        $employee = $this->registrationMatchedEmployee() ?? Employee::create([
            'name' => $this->name,
            'employee_code' => $this->registration_nik,
            'email' => $this->email,
            'is_active' => true,
        ]);

        $this->forceFill(['employee_id' => $employee->id, 'registration_nik' => null])->save();
        $this->setRelation('employee', $employee);
    }

    /**
     * Pendaftaran yang boleh dilihat & diputus penyetuju ini: karyawannya punya
     * outlet dalam scope-nya, ATAU outlet pilihan pendaftar ada dalam scope-nya.
     */
    public function scopeRegistrationsVisibleTo($query, User $reviewer)
    {
        $outletIds = $reviewer->scopedOutletIds();

        // Tanpa batas (admin): termasuk pendaftar yang belum punya data karyawan.
        if ($outletIds === null) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->whereIn('employee_id', Employee::visibleTo($reviewer)->select('id'))
            ->orWhereIn('registration_outlet_id', $outletIds));
    }

    /** Akun hasil registrasi mandiri yang belum selesai (belum verifikasi / belum disetujui / ditolak). */
    public function isRegistrationIncomplete(): bool
    {
        return $this->registration_status !== null;
    }

    /**
     * Dibuang `model:prune` harian: pendaftaran yang tak pernah diverifikasi
     * dan yang ditolak, supaya NIK-nya bisa didaftarkan ulang dan daftar user
     * tak penuh sampah. Akun biasa (status null) tak pernah tersentuh.
     */
    public function prunable()
    {
        return static::where(fn ($q) => $q
            ->where(fn ($w) => $w->where('registration_status', self::REG_UNVERIFIED)
                ->where('registered_at', '<', now()->subDays(self::UNVERIFIED_RETENTION_DAYS)))
            ->orWhere(fn ($w) => $w->where('registration_status', self::REG_REJECTED)
                ->where('reviewed_at', '<', now()->subDays(self::REJECTED_RETENTION_DAYS))));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'registered_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
