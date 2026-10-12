<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perintah yang sudah dikirim ke mesin ('sent') tapi tak pernah dibalas lewat
 * devicecmd dulu nyangkut selamanya — dan perintah sync_time yang nyangkut
 * bahkan memblokir auto-sync jam berikutnya. Kini perintah semacam itu dikirim
 * ulang beberapa kali lalu ditandai 'expired' (lihat DeviceCommandService);
 * kolom ini menghitung berapa kali sudah dikirim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
