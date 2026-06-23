<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan aplikasi (key-value) yang bisa diubah dari UI tanpa edit .env.
 * `key` sengaja memakai notasi dot yang sama dengan config (mis. mekari.client_id)
 * supaya Setting::value() bisa fallback ke config() saat baris belum ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            // Pengelompokan di UI: talenta, adms, general.
            $table->string('group')->default('general');
            // Tipe untuk render input & cast nilai: text, number, boolean, password.
            $table->string('type')->default('text');
            $table->string('label');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
