<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

/**
 * Lupa password via email (semua role). Memakai password broker Laravel:
 * token di tabel password_reset_tokens, kedaluwarsa & jeda kirim ulang dari
 * config/auth.php (passwords.users.expire / throttle).
 *
 * Respon /forgot-password SELALU sama apa pun hasilnya, supaya halaman ini
 * tak bisa dipakai mengintip email mana yang terdaftar. Link hanya dikirim
 * ke akun yang memang bisa login (aktif & pendaftarannya selesai).
 */
class PasswordResetController extends Controller
{
    private const GENERIC_MESSAGE = 'Jika email tersebut terdaftar, link untuk mengatur ulang password telah dikirim. '
        .'Cek kotak masuk / folder spam.';

    public function create()
    {
        return Inertia::render('ForgotPassword', [
            'expiresInMinutes' => $this->expiresInMinutes(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:150']);

        $user = $this->findUser($data['email']);

        if ($user && $user->is_active && ! $user->isRegistrationIncomplete()) {
            // Callback menggantikan notifikasi bawaan (bahasa Inggris, tak
            // diantrekan). Throttle broker tetap berlaku: dalam jeda, callback
            // tak dipanggil dan respon tetap generik.
            $status = PasswordBroker::sendResetLink(['email' => $user->email], function (User $user, string $token) {
                $url = url('/reset-password/'.$token).'?'.http_build_query(['email' => $user->email]);
                $user->notify(new ResetPasswordLink($url, $this->expiresInMinutes()));
            });

            if ($status === PasswordBroker::RESET_LINK_SENT) {
                AuditLog::record('auth.password_reset_requested', $user, actor: $user);
            }
        }

        return back()->with('success', self::GENERIC_MESSAGE);
    }

    public function edit(Request $request, string $token)
    {
        return Inertia::render('ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = $this->findUser($data['email']);

        $status = $user
            ? PasswordBroker::reset(
                ['email' => $user->email, 'token' => $data['token'], 'password' => $data['password']],
                function (User $user, string $password) {
                    // Hash lewat cast 'hashed'. Hash password berubah -> sesi
                    // lain user ini ikut keluar (AuthenticateSession).
                    $user->forceFill([
                        'password' => $password,
                        'must_change_password' => false,
                        'remember_token' => Str::random(60),
                    ])->save();

                    event(new PasswordReset($user));
                },
            )
            : PasswordBroker::INVALID_USER;

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return back()->withErrors([
                'email' => 'Link atur ulang password tidak valid atau sudah kedaluwarsa. Minta link baru.',
            ]);
        }

        AuditLog::record('auth.password_reset', $user, actor: $user);

        return redirect('/login')->with('success', 'Password berhasil diatur ulang. Silakan login dengan password baru.');
    }

    /** Email tak peka huruf besar/kecil (akun lama bisa tersimpan dengan huruf besar). */
    private function findUser(string $email): ?User
    {
        return User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first();
    }

    private function expiresInMinutes(): int
    {
        return (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
    }
}
