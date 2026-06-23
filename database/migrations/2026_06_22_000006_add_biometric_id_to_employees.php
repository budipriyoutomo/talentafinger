<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biometric ID (PIN) master karyawan. Dipakai sebagai dasar TARIK/SEBAR sidik
 * jari dari/ke mesin (PIN di mesin = Biometric ID ini), tanpa bergantung pada
 * mapping per-mesin. Mapping (biometric_id_lokal) tetap jadi fallback bila ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('biometric_id', 50)->nullable()->after('employee_code');
            $table->index('biometric_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['biometric_id']);
            $table->dropColumn('biometric_id');
        });
    }
};
