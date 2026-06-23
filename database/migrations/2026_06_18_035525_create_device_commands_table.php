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
        Schema::create('device_commands', function (Blueprint $table) {
            // id numerik dipakai langsung sebagai cmd id ke mesin (C:<id>:...).
            $table->id();
            $table->uuid('machine_id');
            $table->string('type', 50)->default('sync_time');
            $table->text('command');
            $table->string('status', 20)->default('pending'); // pending | sent | done
            $table->text('response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['machine_id', 'status']);
            $table->foreign('machine_id')->references('id')->on('machines')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
