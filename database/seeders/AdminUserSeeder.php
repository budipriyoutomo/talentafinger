<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Buat akun admin awal untuk login dashboard. Kredensial bisa di-override via
 * env ADMIN_EMAIL / ADMIN_PASSWORD. Idempoten: updateOrCreate by email.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@adms.local');
        $password = env('ADMIN_PASSWORD', 'password');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Administrator'),
                'password' => Hash::make($password),
                // Wajib eksplisit: kolom `role` default-nya 'operator', jadi tanpa
                // ini akun admin awal justru lahir sebagai operator (terkunci dari
                // manajemen user di /settings).
                'role' => 'admin',
            ]
        );
    }
}
