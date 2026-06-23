<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Melacak status sebar sidik jari massal yang berjalan di background
        // (queue job). Frontend membuat 1 baris lalu polling status/progres.
        Schema::create('fingerprint_sync_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_machine_id');
            $table->json('target_machine_ids');          // uuid mesin tujuan
            $table->json('pins');                         // daftar PIN yang disebar
            // queued -> processing -> done | failed
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedInteger('progress_done')->default(0);
            $table->json('summary')->nullable();          // {pins, ok, failed}
            $table->json('items')->nullable();            // hasil per-PIN
            $table->text('error')->nullable();            // error fatal (job batal total)
            $table->timestamps();

            $table->index('status');
            $table->foreign('source_machine_id')->references('id')->on('machines')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_sync_jobs');
    }
};
