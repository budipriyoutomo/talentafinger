<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautkan mesin ke Outlet (Company -> Brand -> Outlet). Kolom `location` yang
 * lama tetap ada sebagai catatan bebas (mis. "Lantai 2 dekat lift"); outlet_id
 * inilah yang jadi acuan resmi untuk pembatasan akses per-outlet nanti.
 *
 * Nullable: mesin lama belum punya outlet dan harus di-assign manual dari UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->uuid('outlet_id')->nullable()->after('name');

            // Outlet dihapus -> mesin tidak ikut terhapus, cukup lepas tautannya.
            $table->foreign('outlet_id')->references('id')->on('outlets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropForeign(['outlet_id']);
            $table->dropColumn('outlet_id');
        });
    }
};
