<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceSourceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FingerprintController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\ManualAttendanceController;
use App\Http\Controllers\OrgController;
use App\Http\Controllers\QrAttendanceController;
use App\Http\Controllers\RegistrationReviewController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TalentaEmployeeSyncController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/**
 * API dashboard. Seluruh grup ini sudah berada di balik middleware `web` + `auth`
 * (lihat bootstrap/app.php), jadi tak ada endpoint di sini yang bisa diakses tamu.
 *
 * Otorisasi TIDAK ditaruh di file ini melainkan di dalam controller, karena tiap
 * aksi butuh dua pemeriksaan yang berbeda: permission (boleh aksinya) dan scope
 * outlet (boleh datanya). Rute yang cukup dijaga permission memakai middleware `can:`.
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

// ===== Kiosk QR absensi (tablet outlet) =====
// Endpoint yang dipanggil TABLET (kiosk/*) ada di routes/web.php dan publik.
Route::get('/kiosks', [KioskController::class, 'index']);
Route::post('/kiosks', [KioskController::class, 'store']);
Route::put('/kiosks/{id}', [KioskController::class, 'update']);
Route::post('/kiosks/{id}/reset', [KioskController::class, 'reset']);
Route::delete('/kiosks/{id}', [KioskController::class, 'destroy']);

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
Route::post('/employees/remove-from-machines', [EmployeeController::class, 'removeFromMachines']);
Route::get('/employees', [EmployeeController::class, 'index']);
Route::post('/employees', [EmployeeController::class, 'store']);
Route::put('/employees/{id}', [EmployeeController::class, 'update']);
Route::delete('/employees/{id}', [EmployeeController::class, 'destroy']);

// ===== Sinkron karyawan dari Talenta =====
// Izin: employee.talenta_sync + akses data semua outlet (dicek di controller).
Route::get('/talenta/employees/summary', [TalentaEmployeeSyncController::class, 'summary']);
Route::get('/talenta/employees/runs', [TalentaEmployeeSyncController::class, 'runs']);
Route::post('/talenta/employees/run', [TalentaEmployeeSyncController::class, 'start']);
Route::get('/talenta/employees/branches', [TalentaEmployeeSyncController::class, 'branches']);
Route::put('/talenta/employees/branches', [TalentaEmployeeSyncController::class, 'saveBranches']);
Route::get('/talenta/employees', [TalentaEmployeeSyncController::class, 'index']);
Route::post('/talenta/employees/{id}/link', [TalentaEmployeeSyncController::class, 'link']);
Route::post('/talenta/employees/{id}/create', [TalentaEmployeeSyncController::class, 'createEmployee']);
Route::post('/talenta/employees/{id}/ignore', [TalentaEmployeeSyncController::class, 'ignore']);

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
// Kirim ulang: log 'failed' MAUPUN log yang sudah 'sent' (body: status=failed|sent).
Route::post('/attendance-logs/resend', [AttendanceController::class, 'resend']);
Route::get('/attendance-logs/resend-jobs/{id}', [AttendanceController::class, 'resendJob']);
Route::post('/attendance-logs/{id}/send', [AttendanceController::class, 'send']);

// ===== Absen manual pakai foto =====
// Dikirim karyawan (role employee) dari halaman /absen; direview supervisor.
Route::post('/attendance/manual', [ManualAttendanceController::class, 'store']);
Route::get('/attendance-logs/{id}/photo', [ManualAttendanceController::class, 'photo']);
// Absen QR: dikirim dari /absen/qr setelah scan QR kiosk (lihat QrAttendanceController).
Route::post('/attendance/qr', [QrAttendanceController::class, 'store'])->middleware('throttle:10,1');
Route::post('/attendance-logs/{id}/review', [ManualAttendanceController::class, 'review']);

// ===== Pengaturan aplikasi =====
Route::put('/settings', [SettingController::class, 'update']);
// Master sumber absensi: sumber mana yang di-sync ke Talenta.
Route::put('/attendance-sources/{id}', [AttendanceSourceController::class, 'update'])->whereNumber('id');

// ===== Manajemen user, role & scope =====
// Pintu: permission user.manage / role.manage. Di dalam, pagar anti-eskalasi
// AccessDelegation: tak seorang pun bisa memberi lebih dari yang ia punya.
Route::middleware('can:user.manage')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    // Buat akun role Karyawan dari data karyawan (halaman Employees).
    Route::post('/users/from-employees', [UserController::class, 'createFromEmployees']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
});

// Persetujuan pendaftaran akun karyawan; scope outlet dicek di controller.
Route::middleware('can:user.registration.review')->group(function () {
    Route::post('/registrations/{id}/approve', [RegistrationReviewController::class, 'approve']);
    Route::post('/registrations/{id}/reject', [RegistrationReviewController::class, 'reject']);
});

Route::middleware('can:role.manage')->group(function () {
    Route::get('/roles', [RoleController::class, 'index']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::put('/roles/{id}', [RoleController::class, 'update']);
    Route::delete('/roles/{id}', [RoleController::class, 'destroy']);
});

// ===== Dashboard =====
Route::get('/dashboard-stats', [DashboardController::class, 'stats']);
