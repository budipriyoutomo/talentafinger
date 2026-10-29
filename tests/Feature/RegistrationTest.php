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

/** Registrasi mandiri akun karyawan: /register, buat password via link email, persetujuan. */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Rahasia123';

    private Company $company;

    private Brand $brand;

    private Outlet $outlet;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['auth.registration_enabled' => true, 'auth.registration_require_approval' => true]);

        $this->company = Company::create(['name' => 'PT Uji']);
        $this->brand = Brand::create(['company_id' => $this->company->id, 'name' => 'Brand Uji']);
        $this->outlet = Outlet::create(['brand_id' => $this->brand->id, 'name' => 'Outlet Uji']);
        $this->employee = $this->employee('Siti', 'K001', 'Siti@Contoh.com');
    }

    private function employee(string $name, string $nik, ?string $email, ?Outlet $outlet = null): Employee
    {
        $employee = Employee::create(['name' => $name, 'employee_code' => $nik, 'email' => $email, 'is_active' => true]);
        $employee->outlets()->sync([($outlet ?? $this->outlet)->id]);

        return $employee;
    }

    private function register(array $overrides = [])
    {
        return $this->from('/register')->post('/register', [
            'nik' => 'K001',
            'email' => 'siti@contoh.com',
            'company_id' => $this->company->id,
            'brand_id' => $this->brand->id,
            'outlet_id' => $this->outlet->id,
            ...$overrides,
        ]);
    }

    private function login(string $email = 'siti@contoh.com')
    {
        return $this->from('/login')->post('/login', ['email' => $email, 'password' => self::PASSWORD]);
    }

    private function registeredUser(): User
    {
        return User::where('employee_id', $this->employee->id)->sole();
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
            ->has('companies', 1)
            ->where('companies.0.id', $this->company->id)
            ->has('companies.0.brands', 1)
            ->has('companies.0.brands.0.outlets', 1)
            ->where('companies.0.brands.0.outlets.0.id', $this->outlet->id));
    }

    public function test_nik_dan_email_cocok_membuat_akun_belum_verifikasi_tanpa_password_dari_pendaftar(): void
    {
        $this->register(['email' => ' SITI@contoh.com ', 'password' => self::PASSWORD])
            ->assertRedirect('/register')->assertSessionHas('success');

        $user = $this->registeredUser();
        $this->assertSame(User::REG_UNVERIFIED, $user->registration_status);
        $this->assertFalse($user->is_active);
        $this->assertSame(Role::EMPLOYEE, $user->role);
        $this->assertSame('siti@contoh.com', $user->email);
        $this->assertSame($this->outlet->id, $user->registration_outlet_id);
        $this->assertFalse($user->must_change_password);
        // Password kiriman pendaftar diabaikan; akun belum bisa dipakai login.
        $this->assertFalse(Hash::check(self::PASSWORD, $user->password));
        $this->login()->assertSessionHasErrors(['email' => 'Email atau password salah.']);
        Notification::assertSentTo($user, VerifyRegistration::class);
    }

    public function test_outlet_wajib_dan_harus_sesuai_rantai_company_brand(): void
    {
        $this->register(['company_id' => '', 'brand_id' => '', 'outlet_id' => ''])
            ->assertSessionHasErrors(['company_id', 'brand_id', 'outlet_id']);

        $otherCompany = Company::create(['name' => 'PT Lain']);
        $this->register(['company_id' => $otherCompany->id])->assertSessionHasErrors('outlet_id');

        $closed = Outlet::create(['brand_id' => $this->brand->id, 'name' => 'Tutup', 'is_active' => false]);
        $this->register(['outlet_id' => $closed->id])->assertSessionHasErrors('outlet_id');

        $this->assertSame(0, User::count());
        Notification::assertNothingSent();
    }

    public function test_data_tak_cocok_atau_sudah_punya_akun_dibalas_pesan_yang_sama(): void
    {
        $generic = $this->register()->assertSessionHas('success')->getSession()->get('success');
        Notification::assertSentTimes(VerifyRegistration::class, 1);
        $this->registeredUser()->forceFill(['registration_status' => null, 'is_active' => true])->save();

        // Email salah, NIK salah, dan karyawan yang sudah punya akun aktif.
        $this->employee('Budi', 'K002', 'budi@contoh.com');
        $this->register(['nik' => 'K002', 'email' => 'salah@contoh.com'])->assertSessionHas('success', $generic);
        $this->register(['nik' => 'X999', 'email' => 'budi@contoh.com'])->assertSessionHas('success', $generic);
        $this->register()->assertSessionHas('success', $generic);

        $this->assertSame(1, User::count());
        Notification::assertSentTimes(VerifyRegistration::class, 1);
    }

    public function test_rate_limit_per_nik_dihitung_per_ip(): void
    {
        // Penyerang di IP lain menghabiskan jatah percobaan NIK K001.
        foreach (range(1, 3) as $_) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.66'])
                ->register(['email' => 'tebak@contoh.com'])->assertSessionHas('success');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.66'])
            ->register(['email' => 'tebak@contoh.com'])->assertSessionHasErrors('nik');

        // Pemilik NIK dari perangkatnya sendiri tetap bisa mendaftar.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
            ->register()->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(User::REG_UNVERIFIED, $this->registeredUser()->registration_status);
    }

    public function test_karyawan_nonaktif_tak_bisa_mendaftar(): void
    {
        $this->employee->update(['is_active' => false]);

        $this->register()->assertSessionHas('success');

        $this->assertSame(0, User::count());
    }

    public function test_membuka_link_email_tidak_mengubah_apa_pun(): void
    {
        $this->register();
        $user = $this->registeredUser();

        // Prefetch email scanner = GET berulang.
        $this->get($this->verificationUrl($user))->assertOk()
            ->assertInertia(fn ($page) => $page->component('RegisterSetPassword')
                ->where('email', 'siti@contoh.com')
                ->where('name', 'Siti'));
        $this->get($this->verificationUrl($user))->assertOk();

        $user->refresh();
        $this->assertSame(User::REG_UNVERIFIED, $user->registration_status);
        $this->assertNull($user->email_verified_at);
    }

    public function test_buat_password_lalu_disetujui_pengelola_outlet_baru_bisa_login(): void
    {
        $this->register();
        $user = $this->registeredUser();

        $this->completeRegistration($user)->assertRedirect('/login')->assertSessionHas('success');
        $user->refresh();
        $this->assertSame(User::REG_PENDING, $user->registration_status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->is_active);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));

        $this->login()->assertSessionHasErrors(['email' => 'Pendaftaran Anda menunggu persetujuan pengelola outlet. Pemberitahuan dikirim lewat email.']);
        $this->assertGuest();

        $reviewer = $this->reviewer();
        $this->actingAs($reviewer)->get('/registrations')->assertOk();
        $this->actingAs($reviewer)->postJson("/api/registrations/{$user->id}/approve")->assertOk();

        $user->refresh();
        $this->assertNull($user->registration_status);
        $this->assertTrue($user->is_active);
        $this->assertSame($reviewer->id, $user->reviewed_by);
        Notification::assertSentTo($user, RegistrationReviewed::class, fn ($n) => $n->approved);

        auth()->logout();
        $this->login()->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_tanpa_wajib_persetujuan_akun_aktif_setelah_buat_password(): void
    {
        config(['auth.registration_require_approval' => false]);
        $newOutlet = Outlet::create(['brand_id' => $this->brand->id, 'name' => 'Outlet Baru']);
        $this->register(['outlet_id' => $newOutlet->id]);
        $user = $this->registeredUser();

        $this->completeRegistration($user);

        $user->refresh();
        $this->assertNull($user->registration_status);
        $this->assertTrue($user->is_active);
        $this->assertTrue($this->employee->outlets()->whereKey($newOutlet->id)->exists());
        $this->login()->assertRedirect();
        $this->assertAuthenticatedAs($user);
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

    public function test_daftar_ulang_saat_belum_verifikasi_membatalkan_link_lama(): void
    {
        $this->register();
        $old = $this->registeredUser();
        $oldUrl = $this->verificationUrl($old);

        $this->travel(11)->minutes(); // lewati rate limit per NIK
        $this->register();
        $new = $this->registeredUser();
        $this->assertNotSame($old->id, $new->id);

        $this->get($oldUrl)->assertRedirect('/login')->assertSessionHas('error');
        $this->completeRegistration($new)->assertRedirect('/login')->assertSessionHas('success');
        $this->assertSame(User::REG_PENDING, $new->fresh()->registration_status);
    }

    public function test_karyawan_tanpa_outlet_masuk_antrian_outlet_pilihannya_dan_ditautkan_saat_disetujui(): void
    {
        $this->employee->outlets()->detach();
        $this->register();
        $user = $this->registeredUser();
        $this->completeRegistration($user);

        $reviewer = $this->reviewer();
        $this->actingAs($reviewer)->get('/registrations')
            ->assertInertia(fn ($page) => $page->component('Registrations')
                ->has('registrations', 1)
                ->where('registrations.0.claimed_outlet.name', 'Outlet Uji')
                ->where('registrations.0.claimed_outlet.is_new', true));

        $this->actingAs($reviewer)->postJson("/api/registrations/{$user->id}/approve")->assertOk();

        $this->assertTrue($this->employee->outlets()->whereKey($this->outlet->id)->exists());
    }

    public function test_penyetuju_di_luar_scope_tidak_melihat_dan_tidak_bisa_memutus(): void
    {
        $this->register();
        $user = $this->registeredUser();
        $this->completeRegistration($user);

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
        $this->register();
        $user = $this->registeredUser();
        $this->completeRegistration($user);

        $this->actingAs($this->reviewer())
            ->postJson("/api/registrations/{$user->id}/reject", ['reason' => 'Bukan staf outlet ini'])
            ->assertOk();
        $this->assertSame(User::REG_REJECTED, $user->fresh()->registration_status);
        Notification::assertSentTo($user, RegistrationReviewed::class, fn ($n) => ! $n->approved && $n->reason === 'Bukan staf outlet ini');

        auth()->logout();
        $this->login()->assertSessionHasErrors(['email' => 'Pendaftaran Anda ditolak: Bukan staf outlet ini. Hubungi HR / atasan Anda.']);

        // Selama masa simpan: daftar ulang diam-diam diabaikan (respon tetap generik).
        $this->travel(11)->minutes(); // lewati rate limit per NIK
        $this->register()->assertSessionHas('success');
        $this->assertSame($user->id, $this->registeredUser()->id);
        Notification::assertSentTimes(VerifyRegistration::class, 1);

        // Setelah masa simpan habis, daftar ulang mengganti pendaftaran yang ditolak.
        $this->travel(User::REJECTED_RETENTION_DAYS + 1)->days();
        $this->register();
        $again = $this->registeredUser();
        $this->assertNotSame($user->id, $again->id);
        $this->assertSame(User::REG_UNVERIFIED, $again->registration_status);
    }

    public function test_admin_mengaktifkan_akun_pendaftar_dari_form_user_berarti_menyetujui(): void
    {
        $newOutlet = Outlet::create(['brand_id' => $this->brand->id, 'name' => 'Outlet Baru']);
        $this->register(['outlet_id' => $newOutlet->id]);
        $user = $this->registeredUser();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@adms.local', 'password' => 'x', 'role' => Role::ADMIN]);

        $this->actingAs($admin)->putJson("/api/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role' => Role::EMPLOYEE,
            'is_active' => true,
            'employee_id' => $user->employee_id,
        ])->assertOk();

        $user->refresh();
        $this->assertNull($user->registration_status);
        $this->assertTrue($user->is_active);
        $this->assertTrue($this->employee->outlets()->whereKey($newOutlet->id)->exists());
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
