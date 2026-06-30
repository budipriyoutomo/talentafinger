<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // API dipanggil dari browser (cookie + CSRF), bukan token stateless,
        // jadi muat di bawah grup `web` (session) + butuh login (`auth`).
        then: function () {
            Route::middleware(['web', 'auth'])
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // HTTPS diterminasi Cloudflare (proxied) -> origin menerima HTTP plus
        // header X-Forwarded-*. Percayai proxy agar Laravel tahu skema aslinya
        // 'https' (URL/redirect/secure-cookie benar). Origin WAJIB dibatasi
        // hanya ke IP range Cloudflare di firewall host (lihat README_DOCKER).
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->validateCsrfTokens(except: [
            'iclock/*',
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Alias untuk membatasi endpoint hanya ke user ber-role admin.
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);

        // Tamu yang belum login diarahkan ke /login; yang sudah login dan membuka
        // halaman guest (mis. /login) diarahkan ke /dashboard.
        $middleware->redirectGuestsTo(fn () => '/login');
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
