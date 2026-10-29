<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Lupa password via email: /forgot-password -> link -> /reset-password. */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'BaruSekali123';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function user(array $attrs = []): User
    {
        return User::create([
            'name' => 'Siti',
            'email' => 'Siti@Contoh.com',
            'password' => Hash::make('LamaSekali123'),
            'role' => 'operator',
            ...$attrs,
        ]);
    }

    private function requestLink(string $email = 'siti@contoh.com')
    {
        return $this->from('/forgot-password')->post('/forgot-password', ['email' => $email]);
    }

    /** @return array{0: string, 1: string} [token, email] dari URL di email. */
    private function linkFor(User $user): array
    {
        $url = null;
        Notification::assertSentTo($user, ResetPasswordLink::class, function ($n) use (&$url) {
            $url = $n->url;

            return true;
        });

        $path = parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return [basename($path), $query['email']];
    }

    private function reset(string $token, string $email, array $overrides = [])
    {
        return $this->from("/reset-password/{$token}")->post('/reset-password', [
            'token' => $token,
            'email' => $email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
            ...$overrides,
        ]);
    }

    public function test_halaman_bisa_dibuka_tamu_dan_login_menautkannya(): void
    {
        $this->get('/forgot-password')->assertOk();
        $this->get('/reset-password/abc?email=a@b.c')->assertOk();
    }

    public function test_email_terdaftar_menerima_link_dan_bisa_mengatur_ulang_password(): void
    {
        $user = $this->user(['must_change_password' => true]);

        $this->requestLink(' SITI@contoh.com ')->assertRedirect('/forgot-password')->assertSessionHas('success');
        [$token, $email] = $this->linkFor($user);
        $this->assertSame('Siti@Contoh.com', $email);

        $this->reset($token, $email)->assertRedirect('/login')->assertSessionHas('success');

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse($user->must_change_password);

        // Token sekali pakai.
        $this->reset($token, $email, ['password' => 'LainLagi123', 'password_confirmation' => 'LainLagi123'])
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));

        $this->from('/login')->post('/login', ['email' => 'Siti@Contoh.com', 'password' => self::NEW_PASSWORD]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_respon_sama_untuk_email_tak_terdaftar_nonaktif_atau_pendaftaran_belum_selesai(): void
    {
        $this->user();
        $generic = $this->requestLink()->getSession()->get('success');
        Notification::assertSentTimes(ResetPasswordLink::class, 1);

        $this->user(['email' => 'off@contoh.com', 'is_active' => false]);
        $this->user(['email' => 'baru@contoh.com', 'is_active' => false, 'registration_status' => User::REG_PENDING]);

        foreach (['tidakada@contoh.com', 'off@contoh.com', 'baru@contoh.com'] as $email) {
            $this->requestLink($email)->assertSessionHas('success', $generic);
        }

        Notification::assertSentTimes(ResetPasswordLink::class, 1);
    }

    public function test_permintaan_beruntun_dibatasi_jeda_broker(): void
    {
        $this->user();

        $this->requestLink();
        $this->requestLink()->assertSessionHas('success');
        Notification::assertSentTimes(ResetPasswordLink::class, 1);

        $this->travel(61)->seconds();
        $this->requestLink();
        Notification::assertSentTimes(ResetPasswordLink::class, 2);
    }

    public function test_token_salah_atau_kedaluwarsa_ditolak(): void
    {
        $user = $this->user();
        $this->requestLink();
        [$token, $email] = $this->linkFor($user);

        $this->reset('token-palsu', $email)->assertSessionHasErrors('email');

        $this->travel(61)->minutes();
        $this->reset($token, $email)->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('LamaSekali123', $user->fresh()->password));
    }

    public function test_password_baru_wajib_memenuhi_kebijakan(): void
    {
        $user = $this->user();
        $this->requestLink();
        [$token, $email] = $this->linkFor($user);

        $this->reset($token, $email, ['password' => 'pendek', 'password_confirmation' => 'pendek'])
            ->assertSessionHasErrors('password');
    }
}
