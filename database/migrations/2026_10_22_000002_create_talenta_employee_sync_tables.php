<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sync karyawan Talenta dua tahap:
 *   Tahap 1 (pull) : Talenta API -> talenta_employees (tabel sementara / snapshot)
 *   Tahap 2 (sync) : talenta_employees -> employees
 *
 * - talenta_employee_sync_runs : riwayat tiap run pull/sync + ringkasan jumlah.
 * - talenta_employees          : 1 baris per karyawan Talenta. Hanya field yang
 *                                dipetakan yang disimpan — PII sensitif (NIK, KTP,
 *                                alamat, payroll, keluarga) sengaja TIDAK disimpan.
 * - talenta_branch_outlets     : mapping cabang Talenta -> outlet lokal, dipakai
 *                                mengisi outlet karyawan yang belum punya outlet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talenta_employee_sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 10);                        // pull | sync
            // NULL = dijalankan scheduler.
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');  // queued->running->success|failed
            $table->unsignedInteger('total_fetched')->default(0);   // pull: baris dari API
            $table->unsignedInteger('count_changed')->default(0);   // pull: baris baru/berubah -> pending
            $table->unsignedInteger('count_created')->default(0);   // sync
            $table->unsignedInteger('count_updated')->default(0);   // sync
            $table->unsignedInteger('count_deactivated')->default(0); // sync
            $table->unsignedInteger('count_conflict')->default(0);  // sync
            $table->unsignedInteger('count_error')->default(0);     // sync
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->index('status');
        });

        Schema::create('talenta_employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('talenta_user_id', 50)->unique();   // user_id Talenta
            $table->string('barcode', 100)->nullable();        // personal.barcode = badgeno
            $table->string('employee_code', 100)->nullable();  // employment.employee_id
            $table->string('full_name', 150);
            $table->string('email', 150)->nullable();
            $table->string('job_position', 150)->nullable();
            $table->string('organization_name', 150)->nullable();
            $table->string('branch_id', 50)->nullable();
            $table->string('branch_name', 150)->nullable();
            $table->string('status', 30)->nullable();          // Active | Resigned
            $table->date('resign_date')->nullable();
            $table->jsonb('payload')->nullable();              // field terpetakan + tambahan non-sensitif
            $table->string('payload_hash', 64);                // berubah -> sync_state kembali pending

            // Hasil pencocokan ke karyawan lokal (Tahap 2).
            $table->uuid('employee_id')->nullable();
            $table->string('match_status', 20)->nullable();    // linked | new | conflict | resigned
            $table->string('sync_state', 20)->default('pending'); // pending | synced | conflict | ignored | error
            $table->text('sync_error')->nullable();            // alasan conflict / error
            $table->timestamp('synced_at')->nullable();

            $table->uuid('last_pull_run_id')->nullable();
            $table->timestamp('fetched_at')->nullable();
            // Terisi bila baris tak muncul lagi di tarikan terakhir. Karyawan lokal tak disentuh.
            $table->timestamp('missing_at')->nullable();
            $table->timestamps();

            $table->index('barcode');
            $table->index('sync_state');
            $table->index('match_status');
            $table->index('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('last_pull_run_id')->references('id')->on('talenta_employee_sync_runs')->nullOnDelete();
        });

        Schema::create('talenta_branch_outlets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('talenta_branch_id', 50)->unique();
            $table->string('talenta_branch_name', 150);        // label, snapshot nama cabang
            $table->uuid('outlet_id');
            $table->timestamps();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talenta_branch_outlets');
        Schema::dropIfExists('talenta_employees');
        Schema::dropIfExists('talenta_employee_sync_runs');
    }
};
