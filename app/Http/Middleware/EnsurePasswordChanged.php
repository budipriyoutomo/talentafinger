<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /** Rute yang tetap boleh dibuka selama password masih sementara. */
    private const ALLOWED = ['password.edit', 'password.update', 'logout'];

    /**
     * User dengan password sementara (hasil seeder / dibuat admin) dikurung di
     * halaman ganti password sampai menggantinya. Tamu & mesin (iclock) lewat.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->must_change_password || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Anda wajib mengganti password sebelum melanjutkan.',
            ], 403);
        }

        return redirect()->route('password.edit');
    }
}
