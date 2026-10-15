<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'must_change_password', 'role', 'is_active', 'last_login_at', 'last_login_ip', 'employee_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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

    /** Akun karyawan (absen foto), bukan pengguna admin panel. */
    public function isEmployee(): bool
    {
        return $this->role === Role::EMPLOYEE;
    }

    /** Role (baris tabel roles) milik user ini. */
    public function roleModel()
    {
        return $this->belongsTo(Role::class, 'role', 'key');
    }

    /** @return string[] */
    public function permissions(): array
    {
        if ($this->resolvedPermissions === null || $this->resolvedPermissions[0] !== $this->role) {
            $this->resolvedPermissions = [$this->role, Role::permissionsFor($this->role)];
        }

        return $this->resolvedPermissions[1];
    }

    /** Data karyawan yang ditautkan ke akun ber-role `employee`. */
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
        ];
    }
}
