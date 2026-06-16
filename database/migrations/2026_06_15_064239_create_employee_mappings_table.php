<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('machine_id');
            $table->string('biometric_id_lokal', 50);
            $table->string('talenta_employee_id', 100);
            $table->string('employee_name', 150)->nullable();
            $table->timestamps();

            $table->unique(['machine_id', 'biometric_id_lokal']);
            $table->foreign('machine_id')->references('id')->on('machines')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_mappings');
    }
};
