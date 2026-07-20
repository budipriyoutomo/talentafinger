<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FingerprintController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\OrgController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\UserController;
use App\Models\AttendanceLog;
use Illuminate\Support\Facades\Route;

/**
 * API dashboard. Seluruh grup ini sudah berada di balik middleware `web` + `auth`
 * (lihat bootstrap/app.php), jadi tak ada endpoint di sini yang bisa diakses tamu.
 *
 * Otorisasi TIDAK ditaruh di file ini melainkan di dalam controller, karena tiap
 * aksi butuh dua pemeriksaan yang berbeda: permission (boleh aksinya) dan scope
 * outlet (boleh datanya). Rute yang cuma butuh peran admin tetap dijaga middleware.
 *
 * Endpoint yang dipanggil MESIN (iclock/*) ada di routes/web.php dan tetap publik.
 */

// ===== Mesin fingerprint =====
Route::get('/machines', [MachineController::class, 'index']);
Route::post('/machines', [MachineController::class, 'store']);
Route::put('/machines/{id}', [MachineController::class, 'update']);
Route::delete('/machines/{id}', [MachineController::class, 'destroy']);
Route::post('/machines/{id}/sync-time', [MachineController::class, 'syncTime']);
Route::post('/machines/{id}/probe-tcp', [MachineController::class, 'probeTcp']);
Route::get('/machines/{id}/zk-info', [MachineController::class, 'zkInfo']);
Route::get('/machines/{id}/zk-users', [MachineController::class, 'zkUsers']);
Route::delete('/machines/{id}/zk-users/{pin}', [MachineController::class, 'deleteZkUser']);
Route::post('/machines/{id}/clear-attendance', [MachineController::class, 'clearAttendance']);

// ===== Sidik jari (TCP 4370 + master template di DB) =====
Route::post('/fingerprint/sync', [FingerprintController::class, 'sync']);
Route::post('/fingerprint/sync-bulk', [FingerprintController::class, 'syncBulk']);
Route::get('/fingerprint/sync-jobs/{id}', [FingerprintController::class, 'syncJob']);
Route::post('/fingerprint/delete-bulk', [FingerprintController::class, 'deleteBulk']);
Route::get('/fingerprint/delete-jobs/{id}', [FingerprintController::class, 'deleteJob']);
Route::post('/fingerprint/distribute-bulk', [FingerprintController::class, 'distributeBulk']);
Route::get('/fingerprint/distribute-jobs/{id}', [FingerprintController::class, 'distributeJob']);

Route::post('/biometric-templates/push', [FingerprintController::class, 'pushTemplate']);
Route::delete('/biometric-templates/{biometricId}', [FingerprintController::class, 'deleteTemplate']);

Route::post('/employees/{id}/fingerprints/capture', [FingerprintController::class, 'capture']);
Route::post('/employees/{id}/fingerprints/distribute', [FingerprintController::class, 'distribute']);
Route::delete('/employees/{id}/fingerprints', [FingerprintController::class, 'destroyEmployeeTemplates']);

// ===== Karyawan =====
// Impor didaftarkan SEBELUM rute {id} agar tak tertangkap sebagai id.
Route::post('/employees/import-from-machine', [EmployeeController::class, 'importFromMachine']);
Route::get('/employees', [EmployeeController::class, 'index']);
Route::post('/employees', [EmployeeController::class, 'store']);
Route::put('/employees/{id}', [EmployeeController::class, 'update']);
Route::delete('/employees/{id}', [EmployeeController::class, 'destroy']);

// ===== Struktur organisasi: Company -> Brand -> Outlet =====
// Membaca boleh siapa saja (disaring ke scope-nya); mengubah hanya admin.
Route::get('/companies', [OrgController::class, 'index']);
Route::post('/companies', [OrgController::class, 'storeCompany']);
Route::put('/companies/{id}', [OrgController::class, 'updateCompany']);
Route::delete('/companies/{id}', [OrgController::class, 'destroyCompany']);
Route::post('/brands', [OrgController::class, 'storeBrand']);
Route::put('/brands/{id}', [OrgController::class, 'updateBrand']);
Route::delete('/brands/{id}', [OrgController::class, 'destroyBrand']);
Route::post('/outlets', [OrgController::class, 'storeOutlet']);
Route::put('/outlets/{id}', [OrgController::class, 'updateOutlet']);
Route::delete('/outlets/{id}', [OrgController::class, 'destroyOutlet']);

// ===== Log absensi =====
Route::get('/attendance-logs', [AttendanceController::class, 'index']);
Route::post('/attendance-logs/send-pending', [AttendanceController::class, 'sendPending']);
Route::post('/attendance-logs/send-failed', [AttendanceController::class, 'sendFailed']);
Route::get('/attendance-logs/resend-jobs/{id}', [AttendanceController::class, 'resendJob']);
Route::post('/attendance-logs/{id}/send', [AttendanceController::class, 'send']);

// ===== Pengaturan aplikasi =====
Route::put('/settings', [SettingController::class, 'update']);

// ===== Manajemen user, role & scope =====
// Dijaga ganda: middleware `admin` di depan pintu, permission user.manage di dalam.
Route::middleware('admin')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
});

Route::get('/dashboard-stats', function (Illuminate\Http\Request $request) {
    // Angka di dashboard ikut scope user: manajer outlet Kopi tak boleh melihat
    // hitungan log outlet lain. visibleTo() disematkan di setiap hitungan.
    $user = $request->user();
    $scoped = fn () => AttendanceLog::visibleTo($user);

    return [
        'logs_today' => $scoped()->whereDate('created_at', today())->count(),
        'sent_count' => $scoped()->where('status_sync', 'sent')->whereDate('created_at', today())->count(),
        'failed_count' => $scoped()->where('status_sync', 'failed')->count(),
        'duplicate_count' => $scoped()->where('status_sync', 'duplicate')->count(),
        'queue_pending' => $scoped()->where('status_sync', 'pending')->count(),
        'queue_processing' => 0,
        'queue_failed' => $scoped()->where('status_sync', 'failed')->count(),
    ];
});
