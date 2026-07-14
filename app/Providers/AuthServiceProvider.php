<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Menyalakan sumbu PERMISSION dari sistem izin (lihat config/permissions.php).
 * Sumbu SCOPE (batas outlet) ditangani policy + scope query `visibleTo()`.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Admin lolos semua gate & policy tanpa perlu didaftarkan satu per satu.
        // Mengembalikan null (bukan false) untuk non-admin supaya pemeriksaan
        // normal tetap berjalan.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        // Tiap permission jadi satu ability bernama persis sama, sehingga bisa
        // dipakai lewat $user->can('machine.manage') atau middleware can:.
        foreach (array_keys(config('permissions.permissions', [])) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
