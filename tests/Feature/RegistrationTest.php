<?php

namespace Tests\Feature;

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

/** Registrasi mandiri akun karyawan: /register, verifikasi email, persetujuan. */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Rahasia123';

    private Outlet $outlet;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['auth.registration_enabled' => true, 'auth.registration_require_approval' => true]);

        $company = Company::create(['name' => 'PT Uji']);
        $brand = Brand::create(['company_id' => $company->id, 'name' => 'Brand Uji']);
        $this->outlet = Outlet::create(['brand_id' => $brand->id, 'name' => 'Outlet Uji']);
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
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
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

    private function verificationUrl(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, VerifyRegistration::class, function ($n) use (&$url) {
            $url = $n->url;

            return true;
        });

        return $url;
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

    public function test_nik_dan_email_cocok_membuat_akun_belum_verifikasi_dan_mengirim_link(): void
    {
        $this->register(['email' => ' SITI@contoh.com '])->assertRedirect('/register')->assertSessionHas('success');

        $user = $this->registeredUser();
        $this->assertSame(User::REG_UNVERIFIED, $user->registration_status);
        $this->assertFalse($user->is_active);
        $this->assertSame(Role::EMPLOYEE, $user->role);
        $this->assertSame('siti@contoh.com', $user->email);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        Notification::assertSentTo($user, VerifyRegistration::class);
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

    public function test_karyawan_nonaktif_tak_bisa_mendaftar(): void
    {
        $this->employee->update(['is_active' => false]);

        $this->register()->assertSessionHas('success');

        $this->assertSame(0, User::count());
    }

    public function test_verifikasi_lalu_disetujui_pengelola_outlet_baru_bisa_login(): void
    {
        $this->register();
        $user = $this->registeredUser();

        $this->get($this->verificationUrl($user))->assertRedirect('/login')->assertSessionHas('success');
        $user->refresh();
        $this->assertSame(User::REG_PENDING, $user->registration_status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->is_active);

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

    public function test_tanpa_wajib_persetujuan_akun_aktif_setelah_verifikasi(): void
    {
        config(['auth.registration_require_approval' => false]);
        $this->register();
        $user = $this->registeredUser();

        $this->get($this->verificationUrl($user));

        $user->refresh();
        $this->assertNull($user->registration_status);
        $this->assertTrue($user->is_active);
        $this->login()->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_link_tak_valid_tidak_memverifikasi(): void
    {
        $this->register();
        $user = $this->registeredUser();

        $this->get($this->verificationUrl($user).'x')->assertRedirect('/login')->assertSessionHas('error');
        $this->get("/register/verify/{$user->id}/".sha1($user->email))->assertRedirect('/login')->assertSessionHas('error');

        $this->assertSame(User::REG_UNVERIFIED, $user->fresh()->registration_status);
    }

    public function test_login_akun_belum_verifikasi_mengirim_ulang_link_maks_sekali_per_menit(): void
    {
        $this->register();
        $user = $this->registeredUser();

        // Baru saja dikirim saat daftar -> belum dikirim ulang.
        $this->login()->assertSessionHasErrors('email');
        Notification::assertSentTimes(VerifyRegistration::class, 1);

        $this->travel(61)->seconds();
        $this->login()->assertSessionHasErrors(['email' => 'Email belum diverifikasi. Link verifikasi baru telah dikirim ke email Anda.']);
        Notification::assertSentTimes(VerifyRegistration::class, 2);
        $this->assertGuest();
    }

    public function test_penyetuju_di_luar_scope_tidak_melihat_dan_tidak_bisa_memutus(): void
    {
        $this->register();
        $user = $this->registeredUser();
        $this->get($this->verificationUrl($user));

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

    public function test_ditolak_menampilkan_alasan_saat_login_dan_boleh_mendaftar_ulang(): void
    {
        $this->register();
        $user = $this->registeredUser();
        $this->get($this->verificationUrl($user));

        $this->actingAs($this->reviewer())
            ->postJson("/api/registrations/{$user->id}/reject", ['reason' => 'Bukan staf outlet ini'])
            ->assertOk();
        $this->assertSame(User::REG_REJECTED, $user->fresh()->registration_status);
        Notification::assertSentTo($user, RegistrationReviewed::class, fn ($n) => ! $n->approved && $n->reason === 'Bukan staf outlet ini');

        auth()->logout();
        $this->login()->assertSessionHasErrors(['email' => 'Pendaftaran Anda ditolak: Bukan staf outlet ini. Hubungi HR / atasan Anda.']);

        // Daftar ulang mengganti pendaftaran yang ditolak.
        $this->register();
        $again = $this->registeredUser();
        $this->assertNotSame($user->id, $again->id);
        $this->assertSame(User::REG_UNVERIFIED, $again->registration_status);
    }

    public function test_admin_mengaktifkan_akun_pendaftar_dari_form_user_berarti_menyetujui(): void
    {
        $this->register();
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
