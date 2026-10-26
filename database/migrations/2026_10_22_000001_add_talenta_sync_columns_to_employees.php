<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom hasil sync karyawan dari Talenta (lihat TODO-Employee-Sync-Talenta.md).
 *
 * - talenta_user_id : `user_id` Talenta, kunci pencocokan yang tak pernah berubah.
 *                     Beda dengan talenta_employee_id (= barcode = badgeno CSV absensi).
 * - email s/d resign_date : salinan data Talenta, ditimpa otomatis tiap sync.
 * - removed_from_machines_at : diisi setelah tombol "Hapus dari Mesin" dijalankan
 *                     untuk karyawan resign, supaya UI tahu sudah dibersihkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('talenta_user_id', 50)->nullable()->unique()->after('talenta_employee_id');
            $table->string('email', 150)->nullable()->after('employee_code');
            $table->string('job_position', 150)->nullable()->after('email');
            $table->string('organization_name', 150)->nullable()->after('job_position');
            $table->string('branch_name', 150)->nullable()->after('organization_name');
            $table->string('talenta_status', 30)->nullable()->after('branch_name');
            $table->date('resign_date')->nullable()->after('talenta_status');
            $table->timestamp('talenta_synced_at')->nullable()->after('resign_date');
            $table->timestamp('removed_from_machines_at')->nullable()->after('talenta_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['talenta_user_id']);
            $table->dropColumn([
                'talenta_user_id',
                'email',
                'job_position',
                'organization_name',
                'branch_name',
                'talenta_status',
                'resign_date',
                'talenta_synced_at',
                'removed_from_machines_at',
            ]);
        });
    }
};
