<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanda "password ini sementara, wajib diganti sebelum memakai aplikasi".
 * Dinyalakan untuk admin hasil seeder dan untuk user yang password-nya dibuat /
 * di-reset oleh admin (lihat UserController); dimatikan saat user menggantinya
 * sendiri (PasswordController). Default false agar user lama tak ikut terkunci.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
