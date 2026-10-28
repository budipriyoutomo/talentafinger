<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Link verifikasi pendaftaran akun karyawan. Diantrekan supaya respon
 * /register tak menunggu SMTP (dan waktunya sama untuk data cocok / tidak).
 * URL dibuat di request (bukan di worker) agar host/skema-nya benar.
 */
class VerifyRegistration extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url, public int $expiresInMinutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verifikasi pendaftaran akun absensi')
            ->greeting("Halo {$notifiable->name},")
            ->line('Akun absensi Anda sudah dibuat. Klik tombol di bawah untuk memverifikasi email ini.')
            ->action('Verifikasi Email', $this->url)
            ->line("Link berlaku {$this->expiresInMinutes} menit. Bila kedaluwarsa, login dengan email & password Anda untuk menerima link baru.")
            ->line('Bila Anda tidak merasa mendaftar, abaikan email ini.');
    }
}
