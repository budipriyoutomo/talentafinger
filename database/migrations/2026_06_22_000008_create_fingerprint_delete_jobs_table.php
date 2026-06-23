<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status hapus massal user (beserta sidik jarinya) dari satu mesin via TCP 4370,
 * berjalan di background. Frontend membuat 1 baris lalu polling progres.
 * Permanen di perangkat; tidak menyentuh master/mapping/log di DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fingerprint_delete_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('machine_id');
            $table->json('pins');                         // daftar PIN yang dihapus
            $table->string('status', 20)->default('queued'); // queued->processing->done|failed
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedInteger('progress_done')->default(0);
            $table->json('summary')->nullable();          // {pins, ok, failed}
            $table->json('items')->nullable();            // hasil per PIN
            $table->text('error')->nullable();            // error fatal
            $table->timestamps();

            $table->index('status');
            $table->foreign('machine_id')->references('id')->on('machines')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_delete_jobs');
    }
};
