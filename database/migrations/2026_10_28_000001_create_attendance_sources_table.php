<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Master sumber absensi (AttendanceLog::SOURCES) + saklar per sumber apakah
 * log-nya dikirim ke Talenta. Barisnya diisi di sini (bukan seeder) karena
 * kode sumber terikat ke kode aplikasi: tanpa baris, pengiriman tak tahu
 * sumber mana yang boleh di-sync.
 *
 * Default: mesin & absen foto tetap di-sync (perilaku lama), QR TIDAK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('sync_to_talenta')->default(true);
            $table->timestamps();
        });

        $now = now();

        DB::table('attendance_sources')->insert([
            [
                'code' => 'machine',
                'name' => 'Mesin Fingerprint',
                'description' => 'Log dari mesin ZKTeco lewat ADMS.',
                'sync_to_talenta' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'manual_photo',
                'name' => 'Absen Foto Manual',
                'description' => 'Absen karyawan pakai foto + lokasi dari halaman /absen (setelah disetujui supervisor).',
                'sync_to_talenta' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'qr',
                'name' => 'Absen QR Outlet',
                'description' => 'Absen karyawan scan QR di tablet outlet (setelah disetujui supervisor).',
                'sync_to_talenta' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sources');
    }
};
