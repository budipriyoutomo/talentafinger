<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotensi ingest ADMS selama ini hanya dijaga di aplikasi (query `exists`
 * lalu insert). Dua POST bersamaan dari mesin yang sama bisa lolos cek itu
 * berbarengan dan menyimpan log kembar — yang lalu terkirim dua kali ke Talenta.
 * Index (machine_id, biometric_id_lokal, timestamp) dijadikan UNIQUE sebagai
 * jaring pengaman di level DB; AdmsController menangkap pelanggarannya dan
 * memperlakukannya sebagai duplikat.
 *
 * Migrasi SENGAJA tidak menghapus baris kembar yang sudah ada: mana yang
 * disimpan (sent vs pending/failed) adalah keputusan operasional. Kalau ada,
 * migrasi berhenti dengan pesan — bereskan dulu, lalu jalankan ulang.
 */
return new class extends Migration
{
    private const COLUMNS = ['machine_id', 'biometric_id_lokal', 'timestamp'];

    public function up(): void
    {
        $duplicateGroups = DB::table('attendance_logs')
            ->select(self::COLUMNS)
            ->groupBy(self::COLUMNS)
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($duplicateGroups > 0) {
            throw new RuntimeException(
                "attendance_logs punya {$duplicateGroups} grup baris kembar (machine_id, biometric_id_lokal, timestamp). "
                . 'Hapus baris kembarnya dulu (sisakan satu per grup, utamakan yang status_sync=sent), lalu jalankan migrate lagi.'
            );
        }

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex(self::COLUMNS);
            $table->unique(self::COLUMNS);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropUnique(self::COLUMNS);
            $table->index(self::COLUMNS);
        });
    }
};
