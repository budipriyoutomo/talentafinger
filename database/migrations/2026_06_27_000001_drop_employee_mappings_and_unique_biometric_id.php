<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pensiunkan mapping per-mesin. Identitas karyawan untuk resolusi badgeno Talenta
 * dan PIN sidik jari kini SEPENUHNYA dari employees.biometric_id (PIN global,
 * konsisten di semua mesin via fingerprint sync). Tabel employee_mappings dihapus.
 *
 * employees.biometric_id dijadikan unique (NULL boleh berulang) supaya 1 PIN
 * memetakan ke tepat 1 karyawan — mencegah resolusi badge yang ambigu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('employee_mappings');

        Schema::table('employees', function (Blueprint $table) {
            // Ganti index biasa dengan unique. drop dulu agar tak bentrok nama.
            $table->dropIndex(['biometric_id']);
            $table->unique('biometric_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['biometric_id']);
            $table->index('biometric_id');
        });

        Schema::create('employee_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('machine_id');
            $table->uuid('employee_id');
            $table->string('biometric_id_lokal', 50);
            $table->timestamps();

            $table->unique(['machine_id', 'biometric_id_lokal']);
            $table->foreign('machine_id')->references('id')->on('machines')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }
};
