<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Buat akun admin awal untuk login dashboard. Kredensial bisa di-override via
 * env ADMIN_EMAIL / ADMIN_PASSWORD.
 *
 * Idempoten & aman diulang: bila akun sudah ada, hanya role-nya dipastikan
 * admin — password TIDAK ditimpa, supaya `db:seed` ulang tak mengembalikan
 * password admin ke nilai default. Akun baru wajib ganti password saat login
 * pertama (must_change_password).
 */
class AdminUserSeeder extends Seeder
{
    /** Password bawaan yang tak boleh dipakai di production. */
    private const DEFAULT_PASSWORD = 'password';

    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@adms.local');
        $password = env('ADMIN_PASSWORD', self::DEFAULT_PASSWORD);

        $user = User::where('email', $email)->first();

        if ($user) {
            // Wajib eksplisit: kolom `role` default-nya 'operator', jadi akun admin
            // yang pernah turun role (mis. akibat migrate:fresh) dipulihkan di sini.
            $user->update(['role' => 'admin']);

            return;
        }

        if (app()->isProduction() && in_array($password, ['', self::DEFAULT_PASSWORD], true)) {
            throw new RuntimeException(
                'ADMIN_PASSWORD wajib di-set (dan bukan "password") sebelum seed admin di production.'
            );
        }

        User::create([
            'name' => env('ADMIN_NAME', 'Administrator'),
            'email' => $email,
            'password' => Hash::make($password),
            'must_change_password' => true,
            'role' => 'admin',
        ]);
    }
}
