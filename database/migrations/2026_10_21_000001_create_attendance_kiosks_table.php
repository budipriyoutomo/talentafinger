<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Absen QR per outlet: tablet "kiosk" di outlet menampilkan QR dinamis yang
 * di-scan karyawan pakai HP sendiri (lihat App\Services\QrTokenService).
 *
 * - attendance_kiosks : satu baris per tablet. Rahasia (secret, kode aktivasi,
 *                       token device) tak pernah disimpan sebagai plaintext
 *                       kecuali `secret` yang di-cast 'encrypted' di model.
 * - attendance_logs   : kiosk_id untuk log source='qr' (log lain: null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_kiosks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('outlet_id');
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);

            // Kunci HMAC QR. Cast 'encrypted' di model (APP_KEY). Dirotasi saat reset aktivasi.
            $table->text('secret');

            // Aktivasi sekali pakai: simpan HASH (sha256), bukan plaintext.
            $table->string('activation_code_hash', 64)->nullable();
            $table->timestamp('activation_expires_at')->nullable();

            // Token device jangka panjang (hash sha256). null = belum/tidak aktif.
            $table->string('device_token_hash', 64)->nullable()->unique();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_seen_ip', 45)->nullable();

            $table->timestamps();
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->index(['outlet_id', 'is_active']);
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->uuid('kiosk_id')->nullable()->after('outlet_id');
            // Kiosk dihapus -> log lama tetap ada, hanya kehilangan tautan kiosk.
            $table->foreign('kiosk_id')->references('id')->on('attendance_kiosks')->nullOnDelete();
            $table->index(['kiosk_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropForeign(['kiosk_id']);
            $table->dropIndex(['kiosk_id', 'created_at']);
            $table->dropColumn('kiosk_id');
        });

        Schema::dropIfExists('attendance_kiosks');
    }
};
