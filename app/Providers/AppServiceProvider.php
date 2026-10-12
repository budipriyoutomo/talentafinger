<?php

namespace App\Providers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rate limit ke Talenta (FR-09) ditegakkan langsung di
        // MekariTalentaService::throttle(), bukan lewat RateLimiter::for().

        // Healthcheck /up: selain "PHP hidup", pastikan database terjangkau.
        // Exception di sini membuat /up membalas HTTP 500, sehingga monitor
        // uptime / healthcheck container ikut menandai aplikasi tidak sehat
        // (session, queue, cache, dan semua data ada di DB).
        Event::listen(DiagnosingHealth::class, function () {
            DB::select('select 1');
        });
    }
}
