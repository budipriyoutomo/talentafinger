<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Link buat password + verifikasi pendaftaran akun karyawan. Diantrekan supaya respon
 * /register tak menunggu SMTP (dan waktunya sama untuk data cocok / tidak).
 * URL dibuat di request (bukan di worker) agar host/skema-nya benar.
 */
class VerifyRegistration extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url, public int $expiresInMinutes)
    {
        // Antrean sendiri: tak tertahan di belakang job zk_sync / pengiriman absensi.
        $this->onQueue('mail');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verifikasi pendaftaran akun absensi')
            ->greeting("Halo {$notifiable->name},")
            ->line('Akun absensi Anda sudah dibuat. Klik tombol di bawah untuk membuat password sekaligus memverifikasi email ini.')
            ->action('Buat Password & Verifikasi', $this->url)
            ->line("Link berlaku {$this->expiresInMinutes} menit. Bila kedaluwarsa, daftar ulang di halaman Registrasi untuk menerima link baru.")
            ->line('Bila Anda tidak merasa mendaftar, abaikan email ini.');
    }
}
