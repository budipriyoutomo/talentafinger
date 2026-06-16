<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;

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
        // FR-09: throttle outgoing requests to Mekari Talenta API to avoid HTTP 429
        RateLimiter::for('mekari-api', function () {
            return Limit::perMinute(config('mekari.rate_limit', 60));
        });
    }
}
