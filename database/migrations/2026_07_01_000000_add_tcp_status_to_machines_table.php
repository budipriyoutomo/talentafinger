<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            // Hasil probe kesehatan jalur TCP 4370 (server -> mesin, pyzk).
            // Terpisah dari last_seen_at/status yang mengukur jalur ADMS
            // (mesin -> server). Diisi command terjadwal `machine:probe-tcp`.
            $table->timestamp('tcp_checked_at')->nullable()->after('sdk_port');
            $table->boolean('tcp_online')->nullable()->after('tcp_checked_at');
            $table->unsignedInteger('tcp_latency_ms')->nullable()->after('tcp_online');
            $table->string('tcp_error')->nullable()->after('tcp_latency_ms');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['tcp_checked_at', 'tcp_online', 'tcp_latency_ms', 'tcp_error']);
        });
    }
};
