<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\VerifyRegistration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Registrasi mandiri akun KARYAWAN (bukan pengguna panel): nama, NIK, email,
 * plus outlet tempat kerja yang dipilih pendaftar. Tidak dicocokkan dengan
 * data karyawan: akun dibuat tanpa karyawan, dan karyawannya baru ditentukan
 * saat disetujui (NIK sudah terdata -> ditautkan, belum -> dibuat dari isian
 * form; lihat User::createRegistrationEmployee).
 *
 * Link dikirim ke email isian; di sanalah pemilik email membuat password,
 * sehingga orang lain tak bisa menentukan password akun tersebut. Karena tak
 * ada pencocokan data, persetujuan pengelola outlet SELALU wajib
 * (RegistrationReviewController) — penyetuju yang memastikan orangnya benar.
 */
class RegistrationController extends Controller
{
    public const LINK_TTL_MINUTES = 60;

    /** Percobaan per NIK per jendela waktu (selain throttle per IP di route). */
    private const MAX_ATTEMPTS_PER_NIK = 3;

    private const NIK_DECAY_SECONDS = 600;

    private const SUCCESS_MESSAGE = 'Link untuk membuat password telah dikirim ke email Anda. '
        .'Cek kotak masuk / folder spam. Link berlaku '.self::LINK_TTL_MINUTES.' menit.';

    public static function enabled(): bool
    {
        return (bool) Setting::value('auth.registration_enabled', false);
    }

    public function show()
    {
        abort_unless(static::enabled(), 404);

        return Inertia::render('Register', [
            'requiresApproval' => true,
            'companies' => $this->orgOptions(),
        ]);
    }

    /**
     * Pilihan Company -> Brand -> Outlet (aktif saja). Hanya cabang yang
     * berujung outlet yang ditampilkan, supaya pilihan tak buntu.
     */
    private function orgOptions(): array
    {
        return Company::where('is_active', true)
            ->with(['brands' => fn ($q) => $q->where('is_active', true)->orderBy('name')
                ->with(['outlets' => fn ($o) => $o->where('is_active', true)->orderBy('name')])])
            ->orderBy('name')
            ->get()
            ->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'brands' => $c->brands
                    ->filter(fn ($b) => $b->outlets->isNotEmpty())
                    ->map(fn ($b) => [
                        'id' => $b->id,
                        'name' => $b->name,
                        'outlets' => $b->outlets->map->only(['id', 'name'])->values(),
                    ])->values(),
            ])
            ->filter(fn ($c) => $c['brands']->isNotEmpty())
            ->values()
            ->all();
    }

    public function store(Request $request)
    {
        abort_unless(static::enabled(), 404);

        $data = $request->validate([
            'name' => 'required|string|max:150',
            'nik' => 'required|string|max:100',
            'email' => 'required|email|max:150',
            'company_id' => 'required|uuid',
            'brand_id' => 'required|uuid',
            'outlet_id' => 'required|uuid',
        ], [], [
            'name' => 'nama',
            'company_id' => 'company',
            'brand_id' => 'brand',
            'outlet_id' => 'outlet',
        ]);

        // Rantai company -> brand -> outlet harus utuh & aktif. Ini data
        // organisasi yang memang ditampilkan di form, jadi boleh ditolak
        // terang-terangan (tak membocorkan apa pun soal karyawan).
        $outlet = Outlet::whereKey($data['outlet_id'])
            ->where('is_active', true)
            ->where('brand_id', $data['brand_id'])
            ->whereHas('brand', fn ($q) => $q->where('is_active', true)
                ->where('company_id', $data['company_id'])
                ->whereHas('company', fn ($c) => $c->where('is_active', true)))
            ->first();

        if (! $outlet) {
            throw ValidationException::withMessages(['outlet_id' => 'Pilih outlet yang valid.']);
        }

        $nik = trim($data['nik']);
        $email = mb_strtolower(trim($data['email']));

        // Kunci NIK + IP: batasi spam pendaftaran per NIK tanpa memblokir
        // pemilik NIK dari perangkatnya sendiri. Batas global per IP tetap
        // dari throttle di route.
        $nikKey = 'register:'.mb_strtolower($nik).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($nikKey, self::MAX_ATTEMPTS_PER_NIK)) {
            throw ValidationException::withMessages([
                'nik' => 'Terlalu banyak percobaan untuk NIK ini. Coba lagi dalam '
                    .ceil(RateLimiter::availableIn($nikKey) / 60).' menit.',
            ]);
        }
        RateLimiter::hit($nikKey, self::NIK_DECAY_SECONDS);

        $this->guardEmail($email);

        try {
            $user = DB::transaction(function () use ($data, $email, $nik, $outlet) {
                // Pendaftaran lama yang belum diverifikasi (email sama, atau NIK
                // sama) diganti: link baru tetap hanya sampai ke pemilik email.
                User::where('registration_status', User::REG_UNVERIFIED)
                    ->where(fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email])
                        ->orWhere(fn ($n) => $n->whereNull('employee_id')->where('registration_nik', $nik)))
                    ->delete();

                return User::create([
                    'name' => trim($data['name']),
                    'email' => $email,
                    // Password acak yang tak pernah diberitahukan ke siapa pun:
                    // akun baru bisa dipakai setelah pemilik email membuat password.
                    'password' => Hash::make(Str::random(64)),
                    'must_change_password' => false,
                    'role' => Role::EMPLOYEE,
                    'is_active' => false,
                    // Karyawannya baru ditentukan saat disetujui (User::createRegistrationEmployee).
                    'employee_id' => null,
                    'registration_nik' => $nik,
                    'registration_status' => User::REG_UNVERIFIED,
                    'registered_at' => now(),
                    'registration_outlet_id' => $outlet->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Dua pendaftaran serentak dengan email yang sama.
            throw ValidationException::withMessages(['email' => 'Email ini baru saja didaftarkan. Cek kotak masuk Anda.']);
        }

        AuditLog::record('auth.registered', $user, ['nik' => $nik, 'email' => $email, 'outlet' => $outlet->name], actor: $user);

        static::sendVerificationLink($user);

        return back()->with('success', self::SUCCESS_MESSAGE);
    }

    /**
     * Email login harus unik. Pendaftaran belum-verifikasi boleh ditimpa;
     * selain itu pendaftar diberi tahu alasannya. Pendaftaran yang DITOLAK baru
     * boleh diulang setelah masa simpannya habis (User::REJECTED_RETENTION_DAYS,
     * lalu dibuang model:prune), supaya antrian penyetuju tak terus terisi.
     */
    private function guardEmail(string $email): void
    {
        $owner = User::whereRaw('LOWER(email) = ?', [$email])->first();

        $message = match (true) {
            ! $owner, $owner->registration_status === User::REG_UNVERIFIED => null,
            $owner->registration_status === User::REG_PENDING => 'Email ini sudah terdaftar dan sedang menunggu persetujuan pengelola outlet.',
            $owner->registration_status === User::REG_REJECTED
                && $owner->reviewed_at?->gt(now()->subDays(User::REJECTED_RETENTION_DAYS)) => 'Pendaftaran dengan email ini sudah ditolak. Hubungi HR / atasan Anda.',
            $owner->registration_status === User::REG_REJECTED => null,
            default => 'Email ini sudah dipakai akun lain. Silakan login, atau pakai "Lupa password".',
        };

        if ($message) {
            throw ValidationException::withMessages(['email' => $message]);
        }

        // Pendaftaran ditolak yang masa simpannya habis: buang supaya email bisa dipakai lagi.
        if ($owner?->registration_status === User::REG_REJECTED) {
            $owner->delete();
        }
    }

    /** Kirim link buat password + verifikasi ke email pendaftar. */
    public static function sendVerificationLink(User $user): void
    {
        $url = URL::temporarySignedRoute(
            'register.verify',
            now()->addMinutes(self::LINK_TTL_MINUTES),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $user->notify(new VerifyRegistration($url, self::LINK_TTL_MINUTES));
    }

    /**
     * GET link dari email: hanya menampilkan form buat password, TANPA
     * mengubah apa pun, supaya prefetch email scanner (Safe Links, antivirus)
     * tak memverifikasi akun. Tak memakai middleware `signed` supaya link
     * kedaluwarsa berujung pesan yang jelas di halaman login, bukan 403.
     */
    public function verify(Request $request, string $id, string $hash)
    {
        $user = $this->userFromLink($request, $id, $hash);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        return Inertia::render('RegisterSetPassword', [
            'name' => $user->name,
            'email' => $user->email,
            // URL POST membawa expires + signature dari link asli.
            'action' => URL::temporarySignedRoute(
                'register.complete',
                now()->setTimestamp((int) $request->query('expires')),
                ['id' => $user->id, 'hash' => $hash],
            ),
        ]);
    }

    /** POST form buat password: simpan password + tandai email terverifikasi. */
    public function complete(Request $request, string $id, string $hash)
    {
        $user = $this->userFromLink($request, $id, $hash);
        if ($user instanceof RedirectResponse) {
            return $user;
        }

        $data = $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        // Tanpa pencocokan data, pendaftar baru selalu lewat persetujuan
        // pengelola outlet. Setting hanya berlaku untuk pendaftaran lama yang
        // sudah tertaut ke karyawan (dibuat sebelum aturan ini).
        $needsApproval = $user->isNewEmployeeRegistration()
            || (bool) Setting::value('auth.registration_require_approval', true);

        // Update bersyarat: dua submit bersamaan, hanya satu yang menang.
        $updated = User::whereKey($user->id)
            ->where('registration_status', User::REG_UNVERIFIED)
            ->update([
                'password' => Hash::make($data['password']),
                'email_verified_at' => now(),
                'registration_status' => $needsApproval ? User::REG_PENDING : null,
                'is_active' => ! $needsApproval,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return redirect('/login')->with('error', 'Link ini sudah dipakai. Silakan login.');
        }

        // Tanpa persetujuan = langsung aktif: tautkan outlet pilihannya sekarang.
        if (! $needsApproval) {
            $user->linkRegistrationOutlet();
        }

        AuditLog::record('auth.email_verified', $user, ['menunggu_persetujuan' => $needsApproval], actor: $user);

        return redirect('/login')->with('success', $needsApproval
            ? 'Password dibuat & email terverifikasi. Akun bisa dipakai setelah disetujui pengelola outlet — pemberitahuan dikirim lewat email.'
            : 'Password dibuat & email terverifikasi. Silakan login.');
    }

    /** User dari link bila signature & hash sah dan masih unverified; selain itu redirect berpesan. */
    private function userFromLink(Request $request, string $id, string $hash): User|RedirectResponse
    {
        $invalid = 'Link tidak valid atau sudah kedaluwarsa. Daftar ulang di halaman Registrasi untuk menerima link baru.';

        if (! $request->hasValidSignature()) {
            return redirect('/login')->with('error', $invalid);
        }

        $user = User::find($id);
        if (! $user || ! hash_equals(sha1($user->email), $hash)) {
            return redirect('/login')->with('error', $invalid);
        }

        return match ($user->registration_status) {
            User::REG_UNVERIFIED => $user,
            User::REG_PENDING => redirect('/login')->with('success', 'Email sudah terverifikasi. Pendaftaran Anda menunggu persetujuan pengelola outlet.'),
            User::REG_REJECTED => redirect('/login')->with('error', 'Pendaftaran Anda sudah ditolak. Hubungi HR / atasan Anda.'),
            default => redirect('/login')->with('success', 'Akun sudah aktif. Silakan login.'),
        };
    }
}
