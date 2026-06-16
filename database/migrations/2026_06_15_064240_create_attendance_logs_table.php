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
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('machine_id');
            $table->string('biometric_id_lokal', 50);
            $table->dateTime('timestamp');
            $table->string('status_sync')->default('pending');
            $table->text('payload_raw')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status_sync');
            $table->index(['machine_id', 'biometric_id_lokal', 'timestamp']);
            $table->foreign('machine_id')->references('id')->on('machines')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
