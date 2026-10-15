<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Satu role = satu paket permission (sumbu 1). Dikelola dari UI; isi awal
 * ditanam migrasi create_roles_tables. `users.role` menyimpan `key`-nya.
 */
class Role extends Model
{
    /** Role bawaan yang dipakai langsung oleh kode (lihat User::isAdmin/isEmployee). */
    public const ADMIN = 'admin';

    public const EMPLOYEE = 'employee';

    private const CACHE_KEY = 'roles.permission_map';

    protected $fillable = ['key', 'name', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    public function users()
    {
        return $this->hasMany(User::class, 'role', 'key');
    }

    /** Daftar permission role ini. */
    public function permissions(): array
    {
        return static::permissionsFor($this->key);
    }

    /** Ganti seluruh permission role ini. */
    public function syncPermissions(array $permissions): void
    {
        DB::transaction(function () use ($permissions) {
            DB::table('role_permissions')->where('role_id', $this->id)->delete();
            DB::table('role_permissions')->insert(array_map(
                fn ($p) => ['role_id' => $this->id, 'permission' => $p],
                array_values(array_unique($permissions)),
            ));
        });

        static::flushCache();
    }

    /**
     * Permission milik role ber-key ini. Peta semua role di-cache karena dibaca
     * di setiap request (sidebar, policy); cache dibuang tiap role berubah.
     */
    public static function permissionsFor(?string $key): array
    {
        if ($key === null) {
            return [];
        }

        return static::permissionMap()[$key] ?? [];
    }

    /** @return array<string, string[]> key role => daftar permission */
    public static function permissionMap(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('roles')
            ->leftJoin('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->get(['roles.key', 'role_permissions.permission'])
            ->groupBy('key')
            ->map(fn ($rows) => $rows->pluck('permission')->filter()->values()->all())
            ->all());
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Permission yang boleh dicentang pada role buatan (katalog minus khusus sistem). */
    public static function assignablePermissions(): array
    {
        return array_values(array_diff(
            array_keys(config('permissions.permissions', [])),
            config('permissions.system_only', []),
        ));
    }

    /** Tambahkan prasyarat (config permissions.requires) ke daftar permission. */
    public static function withRequirements(array $permissions): array
    {
        $requires = config('permissions.requires', []);
        $result = array_values(array_unique($permissions));

        // count() dievaluasi ulang: prasyarat yang baru ditambah ikut diperiksa (bila berantai).
        for ($i = 0; $i < count($result); $i++) {
            foreach ($requires[$result[$i]] ?? [] as $needed) {
                if (! in_array($needed, $result, true)) {
                    $result[] = $needed;
                }
            }
        }

        return $result;
    }
}
