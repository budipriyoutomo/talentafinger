<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status sebar massal sidik jari DARI DB ke mesin (Opsi A), berjalan di background.
 * Beda dengan fingerprint_sync_jobs (mesin->mesin): sumbernya DB, jadi tak ada
 * source_machine_id; yang dipilih adalah daftar KARYAWAN + mesin tujuan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fingerprint_distribute_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->json('employee_ids');                 // karyawan yang disebar
            $table->json('target_machine_ids');           // mesin tujuan
            $table->string('status', 20)->default('queued'); // queued->processing->done|failed
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedInteger('progress_done')->default(0);
            $table->json('summary')->nullable();          // {employees, ok, failed}
            $table->json('items')->nullable();            // hasil per karyawan
            $table->text('error')->nullable();            // error fatal
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_distribute_jobs');
    }
};
