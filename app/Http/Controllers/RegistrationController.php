<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
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
 * Registrasi mandiri akun KARYAWAN (bukan pengguna panel): NIK + email harus
 * cocok dengan data karyawan aktif (email = salinan Talenta), plus outlet
 * tempat kerja yang dipilih pendaftar. Link dikirim ke email itu; di sanalah
 * pemilik email membuat password, sehingga orang lain yang tahu NIK + email
 * tak bisa menentukan password akun tersebut. Setelah itu akun aktif, atau
 * menunggu persetujuan pengelola outlet (setting auth.registration_require_approval,
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

    private const GENERIC_MESSAGE = 'Jika NIK dan email cocok dengan data karyawan, link untuk membuat password telah dikirim ke email tersebut. '
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
            'nik' => 'required|string|max:100',
            'email' => 'required|email|max:150',
            'company_id' => 'required|uuid',
            'brand_id' => 'required|uuid',
            'outlet_id' => 'required|uuid',
        ], [], [
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

        // Kunci NIK + IP: orang iseng yang asal mencoba NIK rekan kerja tak bisa
        // memblokir pendaftaran pemilik NIK dari perangkatnya sendiri. Batas
        // global per IP tetap dari throttle di route.
        $nikKey = 'register:'.mb_strtolower($nik).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($nikKey, self::MAX_ATTEMPTS_PER_NIK)) {
            throw ValidationException::withMessages([
                'nik' => 'Terlalu banyak percobaan untuk NIK ini. Coba lagi dalam '
                    .ceil(RateLimiter::availableIn($nikKey) / 60).' menit.',
            ]);
        }
        RateLimiter::hit($nikKey, self::NIK_DECAY_SECONDS);

        $employee = Employee::where('is_active', true)
            ->whereRaw('TRIM(employee_code) = ?', [$nik])
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->where(fn ($q) => $q->whereNull('resign_date')->orWhere('resign_date', '>', today()))
            ->first();

        if ($employee && $this->claimable($employee, $email)) {
            $this->register($employee, $email, $nik, $outlet);
        }

        return back()->with('success', self::GENERIC_MESSAGE);
    }

    /**
     * Karyawan ini boleh didaftarkan? Belum punya akun (pendaftaran lama yang
     * belum diverifikasi boleh diganti), dan emailnya tak dipakai akun lain.
     * Pendaftaran yang DITOLAK baru boleh diganti setelah masa simpannya habis
     * (User::REJECTED_RETENTION_DAYS, lalu dibuang model:prune), supaya orang
     * yang sama tak terus mengisi antrian penyetuju; sebelum itu hubungi HR.
     */
    private function claimable(Employee $employee, string $email): bool
    {
        $existing = User::where('employee_id', $employee->id)->first();

        if ($existing && ! in_array($existing->registration_status, [User::REG_UNVERIFIED, User::REG_REJECTED], true)) {
            return false;
        }

        if ($existing?->registration_status === User::REG_REJECTED
            && $existing->reviewed_at?->gt(now()->subDays(User::REJECTED_RETENTION_DAYS))) {
            return false;
        }

        $emailOwner = User::whereRaw('LOWER(email) = ?', [$email])->first();

        return ! $emailOwner || ($existing && $emailOwner->is($existing));
    }

    private function register(Employee $employee, string $email, string $nik, Outlet $outlet): void
    {
        try {
            $user = DB::transaction(function () use ($employee, $email, $outlet) {
                User::where('employee_id', $employee->id)
                    ->whereIn('registration_status', [User::REG_UNVERIFIED, User::REG_REJECTED])
                    ->delete();

                return User::create([
                    'name' => $employee->name,
                    'email' => $email,
                    // Password acak yang tak pernah diberitahukan ke siapa pun:
                    // akun baru bisa dipakai setelah pemilik email membuat password.
                    'password' => Hash::make(Str::random(64)),
                    'must_change_password' => false,
                    'role' => Role::EMPLOYEE,
                    'is_active' => false,
                    'employee_id' => $employee->id,
                    'registration_status' => User::REG_UNVERIFIED,
                    'registered_at' => now(),
                    'registration_outlet_id' => $outlet->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Dua pendaftaran serentak untuk karyawan / email yang sama:
            // yang kalah diam saja, respon tetap generik.
            return;
        }

        AuditLog::record('auth.registered', $user, ['nik' => $nik, 'email' => $email, 'outlet' => $outlet->name], actor: $user);

        static::sendVerificationLink($user);
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

        $needsApproval = (bool) Setting::value('auth.registration_require_approval', true);

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
