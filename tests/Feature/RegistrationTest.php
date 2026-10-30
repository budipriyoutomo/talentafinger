<?php

namespace Tests\Feature;

use App\Http\Controllers\RegistrationController;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use App\Models\UserScope;
use App\Notifications\RegistrationReviewed;
use App\Notifications\VerifyRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Registrasi mandiri akun karyawan: /register (tanpa pencocokan data karyawan),
 * buat password via link email, persetujuan pengelola outlet.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Rahasia123';

    private Company $company;

    private Brand $brand;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['auth.registration_enabled' => true, 'auth.registration_require_approval' => true]);

        $this->company = Company::create(['name' => 'PT Uji']);
        $this->brand = Brand::create(['company_id' => $this->company->id, 'name' => 'Brand Uji']);
        $this->outlet = Outlet::create(['brand_id' => $this->brand->id, 'name' => 'Outlet Uji']);
    }

    private function employee(string $name, string $nik, ?string $email, bool $active = true): Employee
    {
        return Employee::create(['name' => $name, 'employee_code' => $nik, 'email' => $email, 'is_active' => $active]);
    }

    private function register(array $overrides = [])
    {
        return $this->from('/register')->post('/register', [
            'name' => 'Rina Baru',
            'nik' => 'N900',
            'email' => 'rina@contoh.com',
            'company_id' => $this->company->id,
            'brand_id' => $this->brand->id,
            'outlet_id' => $this->outlet->id,
            ...$overrides,
        ]);
    }

    private function login(string $email = 'rina@contoh.com')
    {
        return $this->from('/login')->post('/login', ['email' => $email, 'password' => self::PASSWORD]);
    }

    private function registeredUser(string $email = 'rina@contoh.com'): User
    {
        return User::where('email', $email)->sole();
    }

    /** URL dari email terakhir untuk user ini. */
    private function verificationUrl(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, VerifyRegistration::class, function ($n) use (&$url) {
            $url = $n->url;

            return true;
        });

        return $url;
    }

    /** Buka link email lalu kirim form buat password (POST ke `action` dari halaman). */
    private function completeRegistration(User $user, ?string $url = null)
    {
        $action = null;
        $this->get($url ?? $this->verificationUrl($user))
            ->assertInertia(function ($page) use (&$action) {
                $page->component('RegisterSetPassword')
                    ->where('action', function ($value) use (&$action) {
                        $action = $value;

                        return filled($value);
                    });
            });

        return $this->post($action, ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
    }

    private function reviewer(?Outlet $outlet = null): User
    {
        $role = Role::create(['key' => 'spv', 'name' => 'Supervisor']);
        $role->syncPermissions(['user.registration.review']);
        $user = User::create(['name' => 'Spv', 'email' => 'spv@contoh.com', 'password' => Hash::make('x'), 'role' => 'spv']);
        UserScope::create(['user_id' => $user->id, 'scope_type' => 'outlet', 'scope_id' => ($outlet ?? $this->outlet)->id]);

        return $user;
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@adms.local', 'password' => 'x', 'role' => Role::ADMIN]);
    }

    /** Daftar + buat password -> status menunggu persetujuan. */
    private function pendingRegistration(array $overrides = []): User
    {
        $this->register($overrides)->assertSessionHasNoErrors();
        $user = $this->registeredUser($overrides['email'] ?? 'rina@contoh.com');
        $this->completeRegistration($user);

        return $user->fresh();
    }

    public function test_halaman_register_404_bila_dimatikan(): void
    {
        config(['auth.registration_enabled' => false]);

        $this->get('/register')->assertNotFound();
        $this->register()->assertNotFound();
        $this->assertSame(0, User::count());
    }

    public function test_halaman_register_menyediakan_pilihan_company_brand_outlet_aktif(): void
    {
        Outlet::create(['brand_id' => $this->brand->id, 'name' => 'Outlet Tutup', 'is_active' => false]);
        Brand::create(['company_id' => $this->company->id, 'name' => 'Brand Kosong']);

        $this->get('/register')->assertInertia(fn ($page) => $page->component('Register')
            ->where('requiresApproval', true)
            ->has('companies', 1)
            ->where('companies.0.id', $this->company->id)
            ->has('companies.0.brands', 1)
            ->has('companies.0.brands.0.outlets', 1)
            ->where('companies.0.brands.0.outlets.0.id', $this->outlet->id));
    }

    public function test_data_baru_tersimpan_tanpa_pencocokan_dan_link_dikirim(): void
    {
        $this->register(['name' => ' Rina Baru ', 'email' => ' Rina@Contoh.com ', 'password' => self::PASSWORD])
            ->assertRedirect('/register')->assertSessionHas('success');

        $user = $this->registeredUser();
        $this->assertSame('Rina Baru', $user->name);
        $this->assertSame('N900', $user->registration_nik);
        $this->assertNull($user->employee_id);
        $this->assertSame(User::REG_UNVERIFIED, $user->registration_status);
        $this->assertFalse($user->is_active);
        $this->assertSame(Role::EMPLOYEE, $user->role);
        $this->assertSame($this->outlet->id, $user->registration_outlet_id);
        $this->assertSame(0, Employee::count());
        // Password kiriman pendaftar diabaikan; akun belum bisa dipakai login.
        $this->assertFalse(Hash::check(self::PASSWORD, $user->password));
        $this->login()->assertSessionHasErrors(['email' => 'Email atau password salah.']);
        Notification::assertSentTo($user, VerifyRegistration::class);
    }

    public function test_nik_atau_email_yang_sudah_ada_di_data_karyawan_tetap_diterima(): void
    {
        $this->employee('Siti', 'K001', 'siti@contoh.com');

        $this->register(['nik' => 'K001', 'email' => 'lain@contoh.com'])->assertSessionHasNoErrors();
        $this->register(['nik' => 'N901', 'email' => 'siti@contoh.com'])->assertSessionHasNoErrors();

        $this->assertSame(2, User::count());
        Notification::assertSentTimes(VerifyRegistration::class, 2);
    }

    public function test_validasi_nama_dan_rantai_outlet(): void
    {
        $this->register(['name' => ''])->assertSessionHasErrors('name');
        $this->register(['company_id' => '', 'brand_id' => '', 'outlet_id' => ''])
            ->assertSessionHasErrors(['company_id', 'brand_id', 'outlet_id']);

        $otherCompany = Company::create(['name' => 'PT Lain']);
        $this->register(['company_id' => $otherCompany->id])->assertSessionHasErrors('outlet_id');

        $closed = Outlet::create(['brand_id' => $this->brand->id, 'name' => 'Tutup', 'is_active' => false]);
        $this->register(['outlet_id' => $closed->id])->assertSessionHasErrors('outlet_id');

        $this->assertSame(0, User::count());
        Notification::assertNothingSent();
    }

    public function test_email_yang_sudah_dipakai_akun_lain_ditolak_dengan_pesan(): void
    {
        User::create(['name' => 'Lama', 'email' => 'rina@contoh.com', 'password' => 'x', 'role' => 'operator']);

        $this->register()->assertSessionHasErrors(['email' => 'Email ini sudah dipakai akun lain. Silakan login, atau pakai "Lupa password".']);
        $this->assertSame(1, User::count());
        Notification::assertNothingSent();
    }

    public function test_email_yang_menunggu_persetujuan_ditolak_dengan_pesan(): void
    {
        $this->pendingRegistration();

        $this->travel(11)->minutes(); // lewati rate limit per NIK
        $this->register()->assertSessionHasErrors(['email' => 'Email ini sudah terdaftar dan sedang menunggu persetujuan pengelola outlet.']);
    }

    public function test_daftar_ulang_saat_belum_verifikasi_membatalkan_link_lama(): void
    {
        $this->register(['nik' => 'N90']); // salah ketik NIK
        $old = $this->registeredUser();
        $oldUrl = $this->verificationUrl($old);

        $this->register();
        $new = $this->registeredUser();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('N900', $new->registration_nik);

        $this->get($oldUrl)->assertRedirect('/login')->assertSessionHas('error');
        $this->completeRegistration($new)->assertRedirect('/login')->assertSessionHas('success');
        $this->assertSame(User::REG_PENDING, $new->fresh()->registration_status);
    }

    public function test_rate_limit_per_nik_dihitung_per_ip(): void
    {
        foreach (range(1, 3) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.66'])
                ->register(['email' => "spam{$i}@contoh.com"])->assertSessionHasNoErrors();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.66'])
            ->register(['email' => 'spam4@contoh.com'])->assertSessionHasErrors('nik');

        // Dari perangkat lain NIK yang sama tetap bisa mendaftar.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
            ->register()->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(User::REG_UNVERIFIED, $this->registeredUser()->registration_status);
    }

    public function test_membuka_link_email_tidak_mengubah_apa_pun(): void
    {
        $this->register();
        $user = $this->registeredUser();

        // Prefetch email scanner = GET berulang.
        $this->get($this->verificationUrl($user))->assertOk()
            ->assertInertia(fn ($page) => $page->component('RegisterSetPassword')
                ->where('email', 'rina@contoh.com')
                ->where('name', 'Rina Baru'));
        $this->get($this->verificationUrl($user))->assertOk();

        $user->refresh();
        $this->assertSame(User::REG_UNVERIFIED, $user->registration_status);
        $this->assertNull($user->email_verified_at);
    }

    public function test_disetujui_membuat_karyawan_baru_dari_isian_lalu_bisa_login(): void
    {
        // Setting persetujuan dimatikan pun, pendaftar tetap lewat penyetuju.
        config(['auth.registration_require_approval' => false]);
        $user = $this->pendingRegistration();

        $this->assertSame(User::REG_PENDING, $user->registration_status);
        $this->assertFalse($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->login()->assertSessionHasErrors(['email' => 'Pendaftaran Anda menunggu persetujuan pengelola outlet. Pemberitahuan dikirim lewat email.']);
        $this->assertGuest();

        $reviewer = $this->reviewer();
        $this->actingAs($reviewer)->get('/registrations')
            ->assertInertia(fn ($page) => $page->component('Registrations')
                ->has('registrations', 1)
                ->where('registrations.0.nik', 'N900')
                ->where('registrations.0.is_new_employee', true)
                ->where('registrations.0.approval_blocker', null)
                ->where('registrations.0.claimed_outlet.name', 'Outlet Uji'));

        $this->actingAs($reviewer)->postJson("/api/registrations/{$user->id}/approve")->assertOk();

        $user->refresh();
        $employee = $user->employee;
        $this->assertSame('Rina Baru', $employee->name);
        $this->assertSame('N900', $employee->employee_code);
        $this->assertSame('rina@contoh.com', $employee->email);
        $this->assertTrue($employee->is_active);
        $this->assertTrue($employee->outlets()->whereKey($this->outlet->id)->exists());
        $this->assertNull($user->registration_status);
        $this->assertNull($user->registration_nik);
        $this->assertTrue($user->is_active);
        $this->assertSame($reviewer->id, $user->reviewed_by);
        Notification::assertSentTo($user, RegistrationReviewed::class, fn ($n) => $n->approved);

        auth()->logout();
        $this->login()->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_nik_yang_sudah_terdata_ditautkan_ke_karyawan_itu_saat_disetujui(): void
    {
        $siti = $this->employee('Siti', 'K001', null);
        $user = $this->pendingRegistration(['nik' => 'K001']);

        $reviewer = $this->reviewer();
        $this->actingAs($reviewer)->get('/registrations')
            ->assertInertia(fn ($page) => $page->where('registrations.0.matched_employee', 'Siti')
                ->where('registrations.0.is_new_employee', false));

        $this->actingAs($reviewer)->postJson("/api/registrations/{$user->id}/approve")->assertOk();

        $this->assertSame($siti->id, $user->fresh()->employee_id);
        $this->assertSame(1, Employee::count());
        $this->assertTrue($siti->outlets()->whereKey($this->outlet->id)->exists());
    }

    public function test_nik_milik_karyawan_yang_sudah_punya_akun_atau_nonaktif_tak_bisa_disetujui(): void
    {
        $siti = $this->employee('Siti', 'K001', 'siti@contoh.com');
        User::create(['name' => 'Siti', 'email' => 'siti@contoh.com', 'password' => 'x', 'role' => Role::EMPLOYEE, 'employee_id' => $siti->id]);
        $this->employee('Budi', 'K002', null, active: false);

        $taken = $this->pendingRegistration(['nik' => 'K001']);
        $inactive = $this->pendingRegistration(['nik' => 'K002', 'email' => 'budi@contoh.com']);

        $admin = $this->admin();
        $this->actingAs($admin)->postJson("/api/registrations/{$taken->id}/approve")->assertStatus(422);
        $this->actingAs($admin)->postJson("/api/registrations/{$inactive->id}/approve")->assertStatus(422);

        $this->assertSame(User::REG_PENDING, $taken->fresh()->registration_status);
        $this->assertSame(User::REG_PENDING, $inactive->fresh()->registration_status);
    }

    public function test_admin_melihat_semua_pendaftar(): void
    {
        $this->pendingRegistration();

        $this->actingAs($this->admin())->get('/registrations')
            ->assertInertia(fn ($page) => $page->has('registrations', 1));
    }

    public function test_link_tak_valid_tidak_memverifikasi(): void
    {
        $this->register();
        $user = $this->registeredUser();
        $url = $this->verificationUrl($user);
        $form = ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];

        $this->get($url.'x')->assertRedirect('/login')->assertSessionHas('error');
        $this->post($url.'x', $form)->assertRedirect('/login')->assertSessionHas('error');
        $this->post("/register/verify/{$user->id}/".sha1($user->email), $form)->assertRedirect('/login')->assertSessionHas('error');

        $this->travel(RegistrationController::LINK_TTL_MINUTES + 1)->minutes();
        $this->post($url, $form)->assertRedirect('/login')->assertSessionHas('error');

        $user->refresh();
        $this->assertSame(User::REG_UNVERIFIED, $user->registration_status);
        $this->assertFalse(Hash::check(self::PASSWORD, $user->password));
    }

    public function test_link_yang_sudah_dipakai_tak_bisa_mengganti_password(): void
    {
        $this->register();
        $user = $this->registeredUser();
        $url = $this->verificationUrl($user);

        $this->completeRegistration($user, $url);
        $this->post($url, ['password' => 'Lainnya123', 'password_confirmation' => 'Lainnya123'])
            ->assertRedirect('/login');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_penyetuju_di_luar_scope_tidak_melihat_dan_tidak_bisa_memutus(): void
    {
        $user = $this->pendingRegistration();

        $other = Outlet::create(['brand_id' => $this->outlet->brand_id, 'name' => 'Outlet Lain']);
        $reviewer = $this->reviewer($other);

        $this->actingAs($reviewer)->get('/registrations')
            ->assertInertia(fn ($page) => $page->component('Registrations')->has('registrations', 0));
        $this->actingAs($reviewer)->postJson("/api/registrations/{$user->id}/approve")->assertForbidden();
        $this->assertSame(User::REG_PENDING, $user->fresh()->registration_status);

        // Tanpa permission sama sekali.
        $operator = User::create(['name' => 'Op', 'email' => 'op@contoh.com', 'password' => 'x', 'role' => 'operator']);
        $this->actingAs($operator)->postJson("/api/registrations/{$user->id}/approve")->assertForbidden();
        $this->actingAs($operator)->get('/registrations')->assertForbidden();
    }

    public function test_ditolak_menampilkan_alasan_saat_login_dan_baru_boleh_daftar_ulang_setelah_masa_simpan(): void
    {
        $user = $this->pendingRegistration();

        $this->actingAs($this->reviewer())
            ->postJson("/api/registrations/{$user->id}/reject", ['reason' => 'Bukan staf outlet ini'])
            ->assertOk();
        $this->assertSame(User::REG_REJECTED, $user->fresh()->registration_status);
        Notification::assertSentTo($user, RegistrationReviewed::class, fn ($n) => ! $n->approved && $n->reason === 'Bukan staf outlet ini');

        auth()->logout();
        $this->login()->assertSessionHasErrors(['email' => 'Pendaftaran Anda ditolak: Bukan staf outlet ini. Hubungi HR / atasan Anda.']);

        // Selama masa simpan: daftar ulang ditolak dengan pesan.
        $this->travel(11)->minutes(); // lewati rate limit per NIK
        $this->register()->assertSessionHasErrors(['email' => 'Pendaftaran dengan email ini sudah ditolak. Hubungi HR / atasan Anda.']);
        $this->assertSame($user->id, $this->registeredUser()->id);

        // Setelah masa simpan habis, daftar ulang mengganti pendaftaran yang ditolak.
        $this->travel(User::REJECTED_RETENTION_DAYS + 1)->days();
        $this->register()->assertSessionHasNoErrors();
        $again = $this->registeredUser();
        $this->assertNotSame($user->id, $again->id);
        $this->assertSame(User::REG_UNVERIFIED, $again->registration_status);
    }

    public function test_admin_mengaktifkan_pendaftar_dari_form_user_membuat_karyawannya(): void
    {
        $this->register();
        $user = $this->registeredUser();

        $this->actingAs($this->admin())->putJson("/api/users/{$user->id}", [
            'name' => 'Rina',
            'email' => $user->email,
            'role' => Role::EMPLOYEE,
            'is_active' => true,
        ])->assertOk();

        $user->refresh();
        $this->assertNull($user->registration_status);
        $this->assertTrue($user->is_active);
        $this->assertSame('N900', $user->employee?->employee_code);
        $this->assertTrue($user->employee->outlets()->whereKey($this->outlet->id)->exists());
    }

    public function test_prune_membuang_pendaftaran_basi_saja(): void
    {
        $this->register();
        $stale = $this->registeredUser();
        $stale->forceFill(['registered_at' => now()->subDays(User::UNVERIFIED_RETENTION_DAYS + 1)])->save();
        $regular = User::create(['name' => 'Lama', 'email' => 'lama@contoh.com', 'password' => 'x', 'role' => 'operator', 'is_active' => false]);

        $this->artisan('model:prune', ['--model' => [User::class]])->assertSuccessful();

        $this->assertNull($stale->fresh());
        $this->assertNotNull($regular->fresh());
    }
}
