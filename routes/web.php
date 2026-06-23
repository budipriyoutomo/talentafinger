<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdmsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;

// ADMS iClock endpoints — dipanggil mesin fingerprint, harus tetap PUBLIK.
Route::get('/iclock/cdata', [AdmsController::class, 'handshake']);
Route::post('/iclock/cdata', [AdmsController::class, 'ingest']);
Route::get('/iclock/getrequest', [AdmsController::class, 'getrequest']);
Route::post('/iclock/devicecmd', [AdmsController::class, 'devicecmd']);

// Auth (publik). Login pakai guest agar yang sudah login tidak melihat form lagi.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Dashboard UI routes — wajib login.
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/machines', [DashboardController::class, 'machines']);
    Route::get('/attendance-logs', [DashboardController::class, 'attendanceLogs']);
    Route::get('/employee-management', [DashboardController::class, 'employeeManagement']);
    Route::get('/fingerprints', [DashboardController::class, 'fingerprints']);
    Route::get('/settings', [DashboardController::class, 'settings']);
});

Route::redirect('/', '/dashboard');
