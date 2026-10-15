<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    /** Gagal login sebanyak ini dalam LOGIN_DECAY_SECONDS -> dikunci sementara. */
    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    /** Tampilkan form login (halaman tanpa nav/layout). */
    public function showLogin()
    {
        // Sudah login -> langsung ke halaman utamanya.
        if (Auth::check()) {
            return redirect($this->homeFor(Auth::user()));
        }

        return Inertia::render('Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Batasi tebak-password: kunci per email+IP, jadi penyerang tak bisa
        // mengunci akun orang lain dari IP-nya sendiri, dan satu IP tak bisa
        // mencoba tanpa batas pada satu akun.
        $throttleKey = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            AuditLog::record('auth.login_locked', actorName: $credentials['email']);

            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan login. Coba lagi dalam '
                    .RateLimiter::availableIn($throttleKey).' detik.',
            ]);
        }

        // validate() dulu, login() belakangan: akun nonaktif harus ditolak
        // SEBELUM sesi / cookie remember-me sempat dibuat.
        if (! Auth::validate($credentials)) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);
            AuditLog::record('auth.login_failed', actorName: $credentials['email']);

            throw ValidationException::withMessages([
                'email' => 'Email atau password salah.',
            ]);
        }

        /** @var User $user */
        $user = Auth::getLastAttempted();

        // Pesan khusus hanya setelah password terbukti benar, supaya status
        // akun tak bisa diintip dengan menebak email.
        if (! $user->is_active) {
            AuditLog::record('auth.login_blocked', $user, actor: $user);

            throw ValidationException::withMessages([
                'email' => 'Akun Anda dinonaktifkan. Hubungi admin.',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));
        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        AuditLog::record('auth.login');

        return redirect()->intended($this->homeFor($user));
    }

    /** Akun karyawan mendarat di halaman absen foto, sisanya di dashboard. */
    private function homeFor(User $user): string
    {
        return $user->isEmployee() ? '/absen' : '/dashboard';
    }

    private function throttleKey(Request $request): string
    {
        return 'login:'.Str::lower((string) $request->input('email')).'|'.$request->ip();
    }

    public function logout(Request $request)
    {
        AuditLog::record('auth.logout');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
