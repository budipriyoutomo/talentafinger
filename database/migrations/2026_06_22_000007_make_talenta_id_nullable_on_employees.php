<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Talenta ID jadi opsional. Karyawan bisa diimpor dari mesin (hanya punya
 * PIN/Biometric ID + nama) lebih dulu, lalu ditautkan ke Talenta belakangan.
 * Unique tetap berlaku (Postgres mengizinkan banyak NULL pada index unik).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('talenta_employee_id', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('talenta_employee_id', 100)->nullable(false)->change();
        });
    }
};
