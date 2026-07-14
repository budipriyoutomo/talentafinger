<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Data yang dibagikan ke SEMUA halaman Inertia. `auth.user` dipakai layout
     * untuk menampilkan nama user + tombol logout, dan `auth.permissions` dipakai
     * sidebar/tombol untuk menyembunyikan yang tak boleh diakses.
     *
     * Ini murni KOSMETIK. Penegakan yang sesungguhnya ada di policy & scope query
     * di sisi server — menyembunyikan tombol tidak melindungi endpoint-nya.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user
                    ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                    ]
                    : null,
                // Daftar permission yang dimiliki, mis. ['machine.view', ...].
                'permissions' => $user
                    ? array_values(array_filter(
                        array_keys(config('permissions.permissions', [])),
                        fn (string $p) => $user->hasPermission($p),
                    ))
                    : [],
            ],
            // Flash message satu kali (mis. setelah login gagal di luar form).
            'flash' => [
                'error' => $request->session()->get('error'),
                'success' => $request->session()->get('success'),
            ],
        ];
    }
}
