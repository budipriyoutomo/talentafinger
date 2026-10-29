<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Link atur ulang password (lihat PasswordResetController). Diantrekan supaya
 * respon /forgot-password tak menunggu SMTP; URL dibuat di request agar
 * host/skema-nya benar.
 */
class ResetPasswordLink extends Notification implements ShouldQueue
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
            ->subject('Atur ulang password akun absensi')
            ->greeting("Halo {$notifiable->name},")
            ->line('Kami menerima permintaan untuk mengatur ulang password akun Anda.')
            ->action('Atur Ulang Password', $this->url)
            ->line("Link berlaku {$this->expiresInMinutes} menit dan hanya bisa dipakai sekali.")
            ->line('Bila Anda tidak memintanya, abaikan email ini — password Anda tidak berubah.');
    }
}
