<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\VerifyRegistration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Registrasi mandiri akun KARYAWAN (bukan pengguna panel): NIK + email harus
 * cocok dengan data karyawan aktif (email = salinan Talenta), lalu pemilik
 * email memverifikasi lewat link. Setelah itu akun aktif, atau menunggu
 * persetujuan pengelola outlet (setting auth.registration_require_approval,
 * lihat RegistrationReviewController).
 *
 * Respon /register SELALU sama apa pun hasil pencocokannya, supaya halaman
 * ini tak bisa dipakai mengintip NIK / email / siapa yang sudah punya akun.
 */
class RegistrationController extends Controller
{
    public const LINK_TTL_MINUTES = 60;

    /** Percobaan per NIK per jendela waktu (selain throttle per IP di route). */
    private const MAX_ATTEMPTS_PER_NIK = 3;

    private const NIK_DECAY_SECONDS = 600;

    /** Jeda minimal antar email verifikasi untuk satu akun. */
    public const RESEND_DECAY_SECONDS = 60;

    private const GENERIC_MESSAGE = 'Jika NIK dan email cocok dengan data karyawan, link verifikasi telah dikirim ke email tersebut. '
        .'Cek kotak masuk / folder spam. Link berlaku '.self::LINK_TTL_MINUTES.' menit.';

    public static function enabled(): bool
    {
        return (bool) Setting::value('auth.registration_enabled', false);
    }

    public function show()
    {
        abort_unless(static::enabled(), 404);

        return Inertia::render('Register', [
            'requiresApproval' => (bool) Setting::value('auth.registration_require_approval', true),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(static::enabled(), 404);

        $data = $request->validate([
            'nik' => 'required|string|max:100',
            'email' => 'required|email|max:150',
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $nik = trim($data['nik']);
        $email = mb_strtolower(trim($data['email']));

        $nikKey = 'register:'.mb_strtolower($nik);
        if (RateLimiter::tooManyAttempts($nikKey, self::MAX_ATTEMPTS_PER_NIK)) {
            throw ValidationException::withMessages([
                'nik' => 'Terlalu banyak percobaan untuk NIK ini. Coba lagi dalam '
                    .ceil(RateLimiter::availableIn($nikKey) / 60).' menit.',
            ]);
        }
        RateLimiter::hit($nikKey, self::NIK_DECAY_SECONDS);

        // Hash selalu dihitung (cocok atau tidak) supaya lama respon tak
        // membocorkan hasil pencocokan.
        $passwordHash = Hash::make($data['password']);

        $employee = Employee::where('is_active', true)
            ->whereRaw('TRIM(employee_code) = ?', [$nik])
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->where(fn ($q) => $q->whereNull('resign_date')->orWhere('resign_date', '>', today()))
            ->first();

        if ($employee && $this->claimable($employee, $email)) {
            $this->register($employee, $email, $passwordHash, $nik);
        }

        return back()->with('success', self::GENERIC_MESSAGE);
    }

    /**
     * Karyawan ini boleh didaftarkan? Belum punya akun (pendaftaran lama yang
     * belum diverifikasi / ditolak boleh diganti), dan emailnya tak dipakai
     * akun lain.
     */
    private function claimable(Employee $employee, string $email): bool
    {
        $existing = User::where('employee_id', $employee->id)->first();

        if ($existing && ! in_array($existing->registration_status, [User::REG_UNVERIFIED, User::REG_REJECTED], true)) {
            return false;
        }

        $emailOwner = User::whereRaw('LOWER(email) = ?', [$email])->first();

        return ! $emailOwner || ($existing && $emailOwner->is($existing));
    }

    private function register(Employee $employee, string $email, string $passwordHash, string $nik): void
    {
        try {
            $user = DB::transaction(function () use ($employee, $email, $passwordHash) {
                User::where('employee_id', $employee->id)
                    ->whereIn('registration_status', [User::REG_UNVERIFIED, User::REG_REJECTED])
                    ->delete();

                return User::create([
                    'name' => $employee->name,
                    'email' => $email,
                    'password' => $passwordHash,
                    'must_change_password' => false,
                    'role' => Role::EMPLOYEE,
                    'is_active' => false,
                    'employee_id' => $employee->id,
                    'registration_status' => User::REG_UNVERIFIED,
                    'registered_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Dua pendaftaran serentak untuk karyawan / email yang sama:
            // yang kalah diam saja, respon tetap generik.
            return;
        }

        AuditLog::record('auth.registered', $user, ['nik' => $nik, 'email' => $email], actor: $user);

        static::sendVerificationLink($user);
    }

    /** Kirim link verifikasi (dipakai juga AuthController saat akun belum verifikasi login). */
    public static function sendVerificationLink(User $user): void
    {
        RateLimiter::hit('register-resend:'.$user->id, self::RESEND_DECAY_SECONDS);

        $url = URL::temporarySignedRoute(
            'register.verify',
            now()->addMinutes(self::LINK_TTL_MINUTES),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $user->notify(new VerifyRegistration($url, self::LINK_TTL_MINUTES));
    }

    /**
     * Link dari email. Tak memakai middleware `signed` supaya link kedaluwarsa
     * berujung pesan yang jelas di halaman login, bukan halaman 403.
     */
    public function verify(Request $request, string $id, string $hash)
    {
        $invalid = 'Link verifikasi tidak valid atau sudah kedaluwarsa. Login dengan email & password Anda untuk menerima link baru.';

        if (! $request->hasValidSignature()) {
            return redirect('/login')->with('error', $invalid);
        }

        $user = User::find($id);
        if (! $user || ! hash_equals(sha1($user->email), $hash)) {
            return redirect('/login')->with('error', $invalid);
        }

        if ($user->registration_status !== User::REG_UNVERIFIED) {
            return match ($user->registration_status) {
                User::REG_PENDING => redirect('/login')->with('success', 'Email sudah terverifikasi. Pendaftaran Anda menunggu persetujuan pengelola outlet.'),
                User::REG_REJECTED => redirect('/login')->with('error', 'Pendaftaran Anda sudah ditolak. Hubungi HR / atasan Anda.'),
                default => redirect('/login')->with('success', 'Email sudah terverifikasi. Silakan login.'),
            };
        }

        $needsApproval = (bool) Setting::value('auth.registration_require_approval', true);

        $user->forceFill([
            'email_verified_at' => now(),
            'registration_status' => $needsApproval ? User::REG_PENDING : null,
            'is_active' => ! $needsApproval,
        ])->save();

        AuditLog::record('auth.email_verified', $user, ['menunggu_persetujuan' => $needsApproval], actor: $user);

        return redirect('/login')->with('success', $needsApproval
            ? 'Email terverifikasi. Akun Anda bisa dipakai setelah disetujui pengelola outlet — pemberitahuan dikirim lewat email.'
            : 'Email terverifikasi. Silakan login.');
    }
}
