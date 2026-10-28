<?php

use App\Models\AuditLog;
use App\Models\User;
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

// Auto-sync jam mesin. Command menahan diri sesuai pengaturan (on/off,
// interval) dan hanya mengantrekan untuk mesin aktif & online.
Schedule::command('machine:auto-sync-time')->everyMinute()->withoutOverlapping();

// Probe status jalur TCP 4370 (server -> mesin) untuk monitor sidik jari.
// Command menahan diri sesuai pengaturan (on/off, interval) dan menyimpan
// hasil ke kolom tcp_* tiap mesin aktif ber-IP.
Schedule::command('machine:probe-tcp')->everyMinute()->withoutOverlapping();

// Sync karyawan Talenta (tarik ke tabel sementara lalu ke employees). Command
// menahan diri sesuai pengaturan (on/off, interval). runInBackground: 1 run
// bisa ~1 menit+, jangan sampai menahan command terjadwal lain.
Schedule::command('talenta:sync-employees')->everyMinute()->withoutOverlapping()->runInBackground();

// Bersihkan arsip CSV/response kiriman Talenta yang melewati batas retensi
// (setting mekari.file_retention_days) supaya disk VPS tak penuh.
Schedule::command('talenta:prune-files')->dailyAt('02:30')->withoutOverlapping();

// Buang audit log yang lebih tua dari AuditLog::RETENTION_DAYS (365 hari), dan
// pendaftaran akun karyawan yang tak pernah diverifikasi / ditolak (User::prunable).
Schedule::command('model:prune', ['--model' => [AuditLog::class, User::class]])->dailyAt('03:00')->withoutOverlapping();
