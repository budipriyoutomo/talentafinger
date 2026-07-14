<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batas DATA seorang user: daftar Company / Brand / Outlet yang boleh ia sentuh.
 *
 * Satu tabel untuk ketiga level (bukan tiga pivot) supaya satu user bisa dapat
 * campuran, mis. "seluruh Company A" + "satu Outlet milik Company B". Penugasan
 * di level atas otomatis mencakup semua yang di bawahnya:
 *   company -> semua brand & outlet-nya
 *   brand   -> semua outlet-nya
 *
 * User TANPA baris di sini = tidak melihat data apa pun (kecuali admin, yang
 * memang tak pernah dibatasi; lihat User::hasFullDataAccess()).
 *
 * scope_id sengaja TIDAK diberi foreign key: satu kolom menunjuk ke tiga tabel
 * berbeda tergantung scope_type, jadi FK tunggal mustahil. Baris yatim
 * dibersihkan lewat event `deleted` di model Company/Brand/Outlet.
 */
return new class extends Migration
{
    public const TYPES = ['company', 'brand', 'outlet'];

    public function up(): void
    {
        Schema::create('user_scopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 20);
            $table->uuid('scope_id');
            $table->timestamps();

            // Satu user tak perlu ditugaskan dua kali ke target yang sama.
            $table->unique(['user_id', 'scope_type', 'scope_id']);
            // Dipakai saat membersihkan baris yatim setelah outlet/brand dihapus.
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_scopes');
    }
};
