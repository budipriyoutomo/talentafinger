<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hak akses karyawan di MESIN (privilege ZKTeco): 0=user biasa, 14=admin/super.
 * Disimpan di master karyawan agar ikut tersebar saat push template dari DB,
 * sehingga role di tiap mesin konsisten (bukan default jadi user biasa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedTinyInteger('device_privilege')->default(0)->after('outlet_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('device_privilege');
        });
    }
};
