<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outlet yang dipilih pendaftar di /register (lihat RegistrationController).
 * Penyetuju outlet ini ikut melihat pendaftarannya walau karyawan belum
 * tertaut ke outlet mana pun, dan saat disetujui karyawan ditautkan ke outlet
 * ini (pivot employee_outlet). Tetap tersimpan sesudahnya sebagai riwayat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('registration_outlet_id')->nullable()->after('registered_at')
                ->constrained('outlets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registration_outlet_id');
        });
    }
};
