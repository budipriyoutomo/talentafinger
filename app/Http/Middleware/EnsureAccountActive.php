<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    /**
     * Akun yang dinonaktifkan admin langsung terputus di request berikutnya —
     * termasuk sesi yang sudah login dan cookie remember-me — tanpa menunggu
     * sesinya kedaluwarsa. Tamu & mesin (iclock) lewat.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_active) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'Akun Anda dinonaktifkan.'], 401);
        }

        return redirect('/login')->with('error', 'Akun Anda dinonaktifkan. Hubungi admin.');
    }
}
