<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hierarki organisasi master karyawan: Company -> Brand -> Outlet.
 *   1 Company punya banyak Brand; 1 Brand punya banyak Outlet.
 * Karyawan ditautkan ke Outlet (lihat add_outlet_id_to_employees), sehingga
 * Brand & Company karyawan tersirat dari outlet-nya (tak perlu disimpan ulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Nama brand unik dalam satu company (boleh sama antar company).
            $table->unique(['company_id', 'name']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('outlets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('brand_id');
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Nama outlet unik dalam satu brand.
            $table->unique(['brand_id', 'name']);
            $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlets');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('companies');
    }
};
