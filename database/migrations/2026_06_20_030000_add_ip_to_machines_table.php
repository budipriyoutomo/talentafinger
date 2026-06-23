<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            // Alamat LAN mesin untuk koneksi langsung TCP 4370 (sync sidik jari).
            $table->string('ip_address', 45)->nullable()->after('location');
            $table->unsignedInteger('sdk_port')->default(4370)->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'sdk_port']);
        });
    }
};
