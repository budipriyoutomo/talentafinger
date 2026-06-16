<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdmsController;
use App\Http\Controllers\DashboardController;

// ADMS iClock endpoints
Route::get('/iclock/cdata', [AdmsController::class, 'handshake']);
Route::post('/iclock/cdata', [AdmsController::class, 'ingest']);

// Dashboard UI routes
Route::get('/dashboard', [DashboardController::class, 'index']);
Route::get('/machines', [DashboardController::class, 'machines']);
Route::get('/attendance-logs', [DashboardController::class, 'attendanceLogs']);
Route::get('/employee-mappings', [DashboardController::class, 'employeeMappings']);

Route::redirect('/', '/dashboard');
