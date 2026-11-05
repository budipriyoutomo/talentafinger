<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deteksi titip absen (R4): perangkat yang dipakai karyawan untuk absen foto/QR.
 *
 * device_hash = SHA-256 dari UUID acak di cookie `att_device`. Nilai cookie
 * mentah tidak pernah disimpan. Tidak ada fingerprinting browser / IMEI.
 *
 * attendance_logs.risk_flags: tanda (mis. ["new_device","shared_device"]) untuk
 * supervisor. Hanya penanda, tidak pernah memblokir absen. Semua additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->string('device_hash', 64)->index();
            $table->string('user_agent')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->unique(['employee_id', 'device_hash']);
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->json('risk_flags')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn('risk_flags');
        });

        Schema::dropIfExists('employee_devices');
    }
};
