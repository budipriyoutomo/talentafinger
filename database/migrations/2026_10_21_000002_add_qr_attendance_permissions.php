<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Role `employee` mendapat permission absen QR (attendance.qr.create).
 *
 * `kiosk.manage` sengaja TIDAK diberikan ke role mana pun: admin sudah `*`,
 * dan role lain (mis. Manager) bisa dicentang manual dari UI Role.
 */
return new class extends Migration
{
    private const PERMISSION = 'attendance.qr.create';

    private const DESCRIPTION_NEW = 'Akun milik karyawan untuk absen pakai foto (/absen) dan absen QR di outlet (/absen/qr). Tidak bisa membuka admin panel.';

    private const DESCRIPTION_OLD = 'Akun milik karyawan untuk absen pakai foto di halaman /absen. Tidak bisa membuka admin panel.';

    public function up(): void
    {
        $roleId = DB::table('roles')->where('key', 'employee')->value('id');

        if ($roleId !== null) {
            $exists = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission', self::PERMISSION)
                ->exists();

            if (! $exists) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission' => self::PERMISSION,
                ]);
            }

            DB::table('roles')->where('id', $roleId)->update([
                'description' => self::DESCRIPTION_NEW,
                'updated_at' => now(),
            ]);
        }

        Cache::forget('roles.permission_map');
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', self::PERMISSION)->delete();

        DB::table('roles')->where('key', 'employee')->update([
            'description' => self::DESCRIPTION_OLD,
            'updated_at' => now(),
        ]);

        Cache::forget('roles.permission_map');
    }
};
