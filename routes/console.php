<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-send absensi ke Talenta. Command menahan diri sendiri sesuai pengaturan
// (on/off, interval, jam aktif), jadi aman dijalankan tiap menit.
// Wajib ada cron `php artisan schedule:run` tiap menit di server.
Schedule::command('attendance:auto-send')->everyMinute()->withoutOverlapping();
