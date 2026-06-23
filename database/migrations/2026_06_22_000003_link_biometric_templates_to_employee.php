<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opsi A: template sidik jari jadi milik KARYAWAN (master), lepas dari PIN mesin.
 *
 * Sebelumnya biometric_templates dikunci per (biometric_id=PIN, fid) dengan
 * asumsi PIN seragam di semua mesin. Sekarang kunci utamanya employee_id:
 *   1 karyawan -> banyak jari (fid 0-9), tiap jari 1 template.
 *
 * biometric_id dipertahankan (nullable) hanya sebagai catatan PIN asal saat
 * template ditarik. PIN tujuan saat disebar diambil dari employee_mappings
 * (biometric_id_lokal) per mesin, bukan dari kolom ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_templates', function (Blueprint $table) {
            // Unique lama berbasis PIN tak lagi relevan.
            $table->dropUnique('biometric_templates_biometric_id_fid_unique');

            $table->uuid('employee_id')->nullable()->after('id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();

            // biometric_id sekarang opsional (referensi PIN asal saja).
            $table->string('biometric_id', 50)->nullable()->change();
        });

        Schema::table('biometric_templates', function (Blueprint $table) {
            // Satu template per (karyawan, jari).
            $table->unique(['employee_id', 'fid']);
        });
    }

    public function down(): void
    {
        Schema::table('biometric_templates', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'fid']);
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
        });

        Schema::table('biometric_templates', function (Blueprint $table) {
            $table->string('biometric_id', 50)->nullable(false)->change();
            $table->unique(['biometric_id', 'fid'], 'biometric_templates_biometric_id_fid_unique');
        });
    }
};
