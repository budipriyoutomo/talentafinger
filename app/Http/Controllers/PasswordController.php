<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

/**
 * Ganti password SENDIRI — terbuka untuk semua role, termasuk akun karyawan.
 * Juga jadi tujuan paksa EnsurePasswordChanged selama must_change_password aktif.
 *
 * Sesi lain milik user ini otomatis ter-logout: middleware AuthenticateSession
 * membandingkan hash password yang tersimpan di tiap sesi, sedangkan sesi yang
 * sedang dipakai di sini memperbarui hash-nya di akhir request.
 */
class PasswordController extends Controller
{
    public function edit(Request $request)
    {
        return Inertia::render('ChangePassword', [
            'forced' => (bool) $request->user()->must_change_password,
            'home' => $request->user()->isEmployee() ? '/absen' : '/dashboard',
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ], [
            'current_password.current_password' => 'Password saat ini salah.',
            'password.different' => 'Password baru harus berbeda dari password saat ini.',
        ]);

        $user = $request->user();
        $user->password = $request->input('password');
        $user->must_change_password = false;
        $user->save();

        $request->session()->regenerate();

        AuditLog::record('auth.password_changed', $user);

        return redirect($user->isEmployee() ? '/absen' : '/dashboard')
            ->with('success', 'Password berhasil diganti.');
    }
}
