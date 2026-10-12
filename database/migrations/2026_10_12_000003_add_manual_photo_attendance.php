<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Absen manual pakai foto + geolokasi (lihat TODO-Attedance.md).
 *
 * Absen manual DIGABUNG ke attendance_logs (bukan tabel terpisah) supaya
 * auto-send ke Talenta, kirim ulang, dashboard & filter tetap satu jalur.
 * Semua kolom baru nullable / ber-default sehingga aman untuk data produksi:
 * log mesin lama otomatis ber-source 'machine'.
 *
 *   - attendance_logs : machine_id & biometric_id_lokal boleh NULL (log manual tak
 *                       punya mesin), + identitas karyawan, outlet, foto, lokasi,
 *                       dan jejak review supervisor.
 *   - outlets         : titik koordinat untuk geofence.
 *   - employees       : izin per karyawan untuk absen pakai foto (default mati).
 *   - users           : tautan akun ber-role `employee` ke data karyawannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->uuid('machine_id')->nullable()->change();
            $table->string('biometric_id_lokal', 50)->nullable()->change();

            // 'machine' | 'manual_photo'
            $table->string('source', 20)->default('machine')->after('machine_id');
            $table->uuid('employee_id')->nullable()->after('source');
            // Outlet tempat absen manual dilakukan (outlet terdekat yang lolos
            // geofence). Log mesin tetap mengikuti outlet MESIN-nya.
            $table->uuid('outlet_id')->nullable()->after('employee_id');

            $table->string('photo_path')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_meters')->nullable();
            $table->unsignedInteger('distance_meters')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('outlet_id')->references('id')->on('outlets')->nullOnDelete();
            $table->index(['source', 'status_sync']);
            $table->index(['employee_id', 'created_at']);
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('allow_manual_attendance')->default(false);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('employee_id')->nullable()->unique();
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropUnique(['employee_id']);
            $table->dropColumn('employee_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('allow_manual_attendance');
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropForeign(['outlet_id']);
            $table->dropForeign(['reviewed_by']);
            $table->dropIndex(['source', 'status_sync']);
            $table->dropIndex(['employee_id', 'created_at']);
            $table->dropColumn([
                'source', 'employee_id', 'outlet_id', 'photo_path', 'latitude', 'longitude',
                'accuracy_meters', 'distance_meters', 'reviewed_by', 'reviewed_at', 'review_note',
            ]);
        });
        // machine_id / biometric_id_lokal sengaja tak dikembalikan ke NOT NULL:
        // akan gagal bila sudah ada log manual. Hapus log manual dulu bila perlu.
    }
};
