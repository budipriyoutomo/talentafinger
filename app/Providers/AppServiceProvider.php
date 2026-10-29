<?php

namespace App\Providers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        // Database production berisi data yang tak bisa dibuat ulang: blokir
        // migrate:fresh / refresh / reset / rollback & db:wipe di production.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Rate limit ke Talenta (FR-09) ditegakkan langsung di
        // MekariTalentaService::throttle(), bukan lewat RateLimiter::for().

        // Healthcheck /up: selain "PHP hidup", pastikan database terjangkau.
        // Exception di sini membuat /up membalas HTTP 500, sehingga monitor
        // uptime / healthcheck container ikut menandai aplikasi tidak sehat
        // (session, queue, cache, dan semua data ada di DB).
        Event::listen(DiagnosingHealth::class, function () {
            DB::select('select 1');
        });

        // Kebijakan password SATU tempat: dipakai form user (admin) dan form
        // ganti password sendiri lewat Password::defaults().
        Password::defaults(fn () => Password::min(8)->letters()->numbers());
    }
}
