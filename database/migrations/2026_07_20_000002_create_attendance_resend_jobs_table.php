<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status "kirim ulang log gagal" yang berjalan di background, mengikuti pola
 * fingerprint_*_jobs: baris ini yang dipolling frontend untuk progress bar.
 *
 * Yang disimpan adalah FILTER-nya, bukan daftar id hasil. Alasannya: filter itu
 * kecil dan stabil, sedangkan daftar id bisa puluhan ribu baris JSON. Job yang
 * me-resolve sendiri filternya juga otomatis melewati log yang keburu berhasil
 * terkirim lewat jalur lain sebelum job sempat jalan.
 *
 * selected_ids diisi HANYA saat user mencentang baris tertentu — di situ yang
 * dimaksud memang baris spesifik, bukan "semua yang cocok filter".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_resend_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Pemilik job. Dipakai job untuk membatasi log ke outlet wewenangnya,
            // supaya batasan akses tetap berlaku walau eksekusinya di worker.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('filters');                          // filter panel saat tombol ditekan
            $table->json('selected_ids')->nullable();         // null = semua yang cocok filter
            $table->string('status', 20)->default('queued');  // queued->processing->done|failed
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedInteger('progress_done')->default(0);
            $table->json('summary')->nullable();              // {total, sent, failed}
            $table->text('error')->nullable();                // error fatal
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_resend_jobs');
    }
};
