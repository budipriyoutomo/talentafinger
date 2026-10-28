<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registrasi mandiri akun karyawan (lihat RegistrationController).
 *
 * registration_status: null = akun biasa / pendaftaran sudah selesai,
 * 'unverified' = menunggu klik link email, 'pending' = menunggu persetujuan
 * pengelola outlet, 'rejected' = ditolak. Selama tidak null akun tak bisa login
 * (is_active juga false).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('registration_status', 20)->nullable()->after('is_active');
            $table->timestamp('registered_at')->nullable()->after('registration_status');
            $table->foreignId('reviewed_by')->nullable()->after('registered_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('rejection_reason', 255)->nullable()->after('reviewed_at');

            $table->index(['registration_status', 'registered_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['registration_status', 'registered_at']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['registration_status', 'registered_at', 'reviewed_at', 'rejection_reason']);
        });
    }
};
