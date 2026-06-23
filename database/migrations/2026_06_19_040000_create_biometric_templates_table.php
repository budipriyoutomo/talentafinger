<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_templates', function (Blueprint $table) {
            $table->id();
            // PIN (User ID) di mesin. Diasumsikan SAMA di semua mesin,
            // jadi cukup unik per (PIN, jari) tanpa terikat mesin tertentu.
            $table->string('biometric_id', 50);
            $table->unsignedTinyInteger('fid');          // indeks jari 0-9
            $table->unsignedInteger('size')->default(0); // ukuran byte template
            $table->unsignedTinyInteger('valid')->default(1);
            $table->longText('template');                // template sidik jari (base64)
            // Mesin asal enrollment (untuk audit), boleh null.
            $table->uuid('source_machine_id')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();

            $table->unique(['biometric_id', 'fid']);
            $table->index('biometric_id');
            $table->foreign('source_machine_id')->references('id')->on('machines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_templates');
    }
};
