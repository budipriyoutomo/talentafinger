<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Peran yang dikenal. Urutan = dari hak paling tinggi ke rendah. */
    public const ROLES = ['admin', 'manager', 'operator', 'viewer'];

    /**
     * Samakan dengan default kolom `role` di DB. Tanpa ini, user yang dibuat
     * tanpa menyebut role punya role NULL di memori (default DB baru terpakai
     * setelah di-reload), sehingga semua pemeriksaan izin menolaknya.
     */
    protected $attributes = [
        'role' => 'operator',
    ];

    /**
     * Daftar outlet_id efektif user ini, di-hitung sekali per instance karena
     * dipakai berulang di banyak query dalam satu request. null = tanpa batas.
     */
    private ?array $resolvedScope = null;

    /** Hanya admin yang boleh mengelola user & role serta pengaturan aplikasi. */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Penugasan user ke Company / Brand / Outlet (lihat model UserScope). */
    public function dataScopes()
    {
        return $this->hasMany(UserScope::class);
    }

    /**
     * Boleh melakukan aksi ini? Sumbu PERTAMA dari izin (verb), lepas dari
     * outlet mana datanya. Peta role -> permission ada di config/permissions.php.
     */
    public function hasPermission(string $permission): bool
    {
        $granted = config("permissions.roles.{$this->role}", []);

        return in_array('*', $granted, true)
            || in_array($permission, $granted, true);
    }

    /**
     * Bebas dari batasan outlet? Saat ini hanya admin. Dipisah dari isAdmin()
     * supaya kalau kelak ada role global lain, cukup ubah di sini.
     */
    public function hasFullDataAccess(): bool
    {
        return $this->isAdmin();
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

        $companyIds = $idsOf('company');
        $brandIds = $idsOf('brand');
        $outletIds = $idsOf('outlet');

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
        ];
    }
}
