<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - audit_logs : jejak aksi sensitif (login, user/role, setting, mesin, kirim
 *                Talenta, review absen foto). Lihat App\Models\AuditLog.
 * - users      : is_active (nonaktifkan akun tanpa menghapus; histori tetap
 *                tertaut) + jejak login terakhir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Aktor. NULL untuk percobaan login gagal / aksi sistem. nullOnDelete:
            // menghapus user tak boleh menghapus jejaknya, maka nama di-snapshot.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 150)->nullable();
            // Mis. 'auth.login', 'user.updated', 'role.deleted'.
            $table->string('action', 60);
            $table->string('target_type', 40)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->string('target_label', 200)->nullable();
            // Rincian perubahan (sebelum/sesudah). Rahasia tak pernah disimpan.
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'last_login_at', 'last_login_ip']);
        });

        Schema::dropIfExists('audit_logs');
    }
};
