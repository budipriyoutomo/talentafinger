<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Hanya user ber-role admin yang boleh lanjut. Dipakai untuk endpoint
     * manajemen user & role.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! optional($request->user())->isAdmin()) {
            abort(403, 'Hanya admin yang boleh mengakses sumber daya ini.');
        }

        return $next($request);
    }
}
