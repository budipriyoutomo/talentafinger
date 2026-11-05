<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index tunggal attendance_logs(timestamp) untuk laporan pengecualian (R3),
 * yang memfilter rentang waktu tanpa machine_id. Index unik gabungan
 * (machine_id, biometric_id_lokal, timestamp) tak bisa dipakai untuk itu.
 *
 * PostgreSQL: CREATE INDEX CONCURRENTLY supaya tabel tidak terkunci selama
 * index dibangun (harus di luar transaksi). Driver lain (SQLite di test):
 * index biasa.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'attendance_logs_timestamp_index';

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::INDEX . ' ON attendance_logs ("timestamp")');

            return;
        }

        Schema::table('attendance_logs', fn ($table) => $table->index('timestamp', self::INDEX));
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::INDEX);

            return;
        }

        Schema::table('attendance_logs', fn ($table) => $table->dropIndex(self::INDEX));
    }
};
