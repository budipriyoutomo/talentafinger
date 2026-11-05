<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R1-B: tandai absen QR yang disetujui otomatis oleh sistem. reviewed_by saja
 * tidak cukup (NULL juga berarti "belum direview"). Additive, default false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->boolean('auto_approved')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn('auto_approved');
        });
    }
};
