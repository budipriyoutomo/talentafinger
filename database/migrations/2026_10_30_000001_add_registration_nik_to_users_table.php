<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registrasi mandiri tanpa data karyawan: NIK isian pendaftar disimpan di sini
 * (employee_id masih null). Karyawan baru dibuat saat pendaftaran disetujui,
 * lalu kolom ini dikosongkan lagi (lihat User::createRegistrationEmployee).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('registration_nik', 100)->nullable()->after('registration_outlet_id');
            $table->index('registration_nik');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['registration_nik']);
            $table->dropColumn('registration_nik');
        });
    }
};
