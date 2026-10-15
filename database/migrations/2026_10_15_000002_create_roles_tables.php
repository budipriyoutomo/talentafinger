<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role dinamis: peta role -> permission pindah dari config/permissions.php ke
 * database supaya bisa dikelola dari UI.
 *
 * `users.role` TETAP menyimpan key role (string), jadi data user lama tak
 * perlu diubah. Key tak bisa diganti setelah dibuat (RoleController).
 *
 * Isi awal di bawah adalah SNAPSHOT peta lama di config — sengaja ditulis di
 * sini, bukan dibaca dari config, agar migrasi tetap bisa dijalankan ulang.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'admin' => [
            'name' => 'Admin',
            'description' => 'Akses penuh termasuk kelola user, role, pengaturan, & struktur organisasi. Tidak dibatasi outlet.',
            'is_system' => true,
            'permissions' => ['*'],
        ],
        'manager' => [
            'name' => 'Manager',
            'description' => 'Kelola karyawan & sidik jari, kirim ulang absensi, review absen foto. Tidak bisa mengubah mesin.',
            'is_system' => false,
            'permissions' => [
                'machine.view', 'attendance.view', 'attendance.send', 'attendance.review',
                'employee.view', 'employee.manage', 'fingerprint.view', 'fingerprint.sync', 'fingerprint.delete',
            ],
        ],
        'operator' => [
            'name' => 'Operator',
            'description' => 'Operasi harian & kirim absensi, tanpa hak menghapus.',
            'is_system' => false,
            'permissions' => [
                'machine.view', 'attendance.view', 'attendance.send', 'employee.view',
                'fingerprint.view', 'fingerprint.sync',
            ],
        ],
        'viewer' => [
            'name' => 'Viewer',
            'description' => 'Hanya melihat data, tidak bisa mengubah apa pun.',
            'is_system' => false,
            'permissions' => ['machine.view', 'attendance.view', 'employee.view', 'fingerprint.view'],
        ],
        'employee' => [
            'name' => 'Karyawan',
            'description' => 'Akun milik karyawan untuk absen pakai foto di halaman /absen. Tidak bisa membuka admin panel.',
            'is_system' => true,
            'permissions' => ['attendance.manual.create'],
        ],
    ];

    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            // Nilai yang disimpan di users.role. Huruf kecil/angka/underscore.
            $table->string('key', 50)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            // Role sistem (admin, employee) tak bisa diubah / dihapus.
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 100);
            $table->unique(['role_id', 'permission']);
        });

        $now = now();
        foreach (self::DEFAULTS as $key => $role) {
            $id = DB::table('roles')->insertGetId([
                'key' => $key,
                'name' => $role['name'],
                'description' => $role['description'],
                'is_system' => $role['is_system'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('role_permissions')->insert(array_map(
                fn ($p) => ['role_id' => $id, 'permission' => $p],
                $role['permissions'],
            ));
        }

        Cache::forget('roles.permission_map');
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Cache::forget('roles.permission_map');
    }
};
