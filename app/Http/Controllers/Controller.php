<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

abstract class Controller
{
    // Menyediakan $this->authorize(...) untuk policy. Laravel tidak lagi memuat
    // trait ini secara default sejak versi 11.
    use AuthorizesRequests;

    /**
     * Periksa permission BEBAS-MODEL (sumbu 1), mis. 'fingerprint.sync' yang
     * tidak menempel ke satu baris pun. Untuk aksi pada satu baris, pakai
     * $this->authorize() dengan policy supaya scope outlet ikut diperiksa.
     */
    protected function authorizePermission(Request $request, string $permission): void
    {
        if (! $request->user()?->hasPermission($permission)) {
            throw new AccessDeniedHttpException("Anda tidak punya izin: {$permission}.");
        }
    }

    /**
     * Pastikan outlet tujuan berada dalam scope user (sumbu 2). Dipakai saat user
     * MENULIS outlet_id — tanpa ini, seseorang bisa memindahkan data ke outlet
     * yang bukan wewenangnya, atau menaruhnya di luar jangkauan siapa pun.
     *
     * Outlet null = "belum ditempatkan"; hanya user tanpa batas (admin) yang boleh.
     */
    protected function assertOutletInScope(Request $request, ?string $outletId): void
    {
        if (! $request->user()?->canAccessOutlet($outletId)) {
            throw new AccessDeniedHttpException(
                $outletId === null
                    ? 'Hanya admin yang boleh membiarkan data tanpa outlet.'
                    : 'Outlet tujuan berada di luar wewenang Anda.',
            );
        }
    }

    /** Versi jamak dari assertOutletInScope(), untuk data ber-banyak outlet. */
    protected function assertOutletsInScope(Request $request, array $outletIds): void
    {
        foreach ($outletIds as $outletId) {
            $this->assertOutletInScope($request, $outletId);
        }
    }
}
