<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Halaman Log Absensi mengurutkan dengan `order by created_at desc`, tapi kolom
 * itu tak punya index sama sekali — jadi tiap ganti halaman Postgres menyortir
 * ulang seluruh tabel. Belum terasa di data kecil, tapi jadi mahal begitu log
 * produksi menumpuk (apalagi digabung OFFSET besar).
 *
 * Index kedua melayani tab "Gagal", yang menyaring status_sync lalu mengurutkan
 * created_at dalam satu query — index gabungan membuat filter + sort sekaligus
 * terlayani, sedangkan index status_sync yang lama hanya melayani filternya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->index('created_at');
            $table->index(['status_sync', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['status_sync', 'created_at']);
        });
    }
};
