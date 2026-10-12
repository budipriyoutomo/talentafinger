<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
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

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Email atau password salah.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended($this->homeFor($request->user()));
    }

    /** Akun karyawan mendarat di halaman absen foto, sisanya di dashboard. */
    private function homeFor(User $user): string
    {
        return $user->isEmployee() ? '/absen' : '/dashboard';
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
