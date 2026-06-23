<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Peran akses aplikasi: admin (kelola semua + user), operator (kelola
            // data operasional), viewer (hanya lihat). Default 'operator' agar
            // user baru tak otomatis dapat hak admin.
            $table->string('role')->default('operator')->after('password');
        });

        // User yang sudah ada (mis. admin hasil seed) dijadikan admin penuh
        // supaya tidak ada yang kehilangan akses setelah migrasi.
        DB::table('users')->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
