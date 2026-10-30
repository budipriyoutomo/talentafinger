<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdmsController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeePresenceController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\KioskDeviceController;
use App\Http\Controllers\ManualAttendanceController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\QrAttendanceController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\RegistrationReviewController;

// ADMS iClock endpoints — dipanggil mesin fingerprint, harus tetap PUBLIK.
Route::get('/iclock/cdata', [AdmsController::class, 'handshake']);
Route::post('/iclock/cdata', [AdmsController::class, 'ingest']);
Route::get('/iclock/getrequest', [AdmsController::class, 'getrequest']);
Route::post('/iclock/devicecmd', [AdmsController::class, 'devicecmd']);

// Tablet kiosk absen QR di outlet — PUBLIK, diautentikasi token device
// (header X-Kiosk-Token), bukan login user. kiosk/* bebas CSRF (bootstrap/app.php).
Route::get('/kiosk', [KioskDeviceController::class, 'show']);
Route::post('/kiosk/activate', [KioskDeviceController::class, 'activate'])->middleware('throttle:10,1');
Route::get('/kiosk/state', [KioskDeviceController::class, 'state'])->middleware('throttle:120,1');

// Tujuan QR kiosk (?k&w&s) sekaligus halaman absen QR. Di luar grup auth:
// tamu yang scan disimpan scan-nya di session, lalu diarahkan login.
Route::get('/absen/qr', [QrAttendanceController::class, 'scan'])->middleware('throttle:30,1');

// Auth (publik). Login pakai guest agar yang sudah login tidak melihat form lagi.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);

    // Registrasi mandiri akun karyawan (404 bila setting auth.registration_enabled mati).
    Route::get('/register', [RegistrationController::class, 'show']);
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:5,1');

    // Lupa password via email (semua role). Link berisi token dari password broker.
    Route::get('/forgot-password', [PasswordResetController::class, 'create']);
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
// Link dari email registrasi (signed). GET = form buat password (tanpa ubah
// state, aman dari prefetch email scanner), POST = simpan password + verifikasi.
// Di luar guest: link boleh dibuka di browser yang sedang login akun lain.
Route::get('/register/verify/{id}/{hash}', [RegistrationController::class, 'verify'])
    ->middleware('throttle:10,1')->name('register.verify');
Route::post('/register/verify/{id}/{hash}', [RegistrationController::class, 'complete'])
    ->middleware('throttle:10,1')->name('register.complete');

// Dashboard UI routes — wajib login.
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/machines', [DashboardController::class, 'machines']);
    Route::get('/kiosks', [KioskController::class, 'page']);
    Route::get('/attendance-logs', [DashboardController::class, 'attendanceLogs']);
    // Laporan: karyawan absen di mesin mana saja + identitas mesinnya.
    Route::get('/employee-presence', [EmployeePresenceController::class, 'index']);
    // Raw log satu orang di satu tanggal (popup Detail).
    Route::get('/employee-presence/logs', [EmployeePresenceController::class, 'logs']);
    Route::get('/employee-management', [DashboardController::class, 'employeeManagement']);
    Route::get('/fingerprints', [DashboardController::class, 'fingerprints']);
    Route::get('/settings', [DashboardController::class, 'settings']);
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    // Antrian pendaftaran akun karyawan (permission user.registration.review).
    Route::get('/registrations', [RegistrationReviewController::class, 'index']);

    // Halaman absen foto untuk akun karyawan (role employee).
    Route::get('/absen', [ManualAttendanceController::class, 'show']);

    // Ganti password sendiri (semua role); juga tujuan paksa EnsurePasswordChanged.
    Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
});

Route::redirect('/', '/dashboard');
