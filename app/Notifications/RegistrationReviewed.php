<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Hasil review pendaftaran akun karyawan (disetujui / ditolak). */
class RegistrationReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public bool $approved,
        public string $loginUrl,
        public ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting("Halo {$notifiable->name},");

        if ($this->approved) {
            return $mail->subject('Akun absensi Anda sudah aktif')
                ->line('Pendaftaran akun absensi Anda telah disetujui. Anda sekarang bisa login.')
                ->action('Login', $this->loginUrl);
        }

        $mail->subject('Pendaftaran akun absensi ditolak')
            ->line('Maaf, pendaftaran akun absensi Anda ditolak.');

        if ($this->reason) {
            $mail->line("Alasan: {$this->reason}");
        }

        return $mail->line('Hubungi HR atau atasan Anda bila ada pertanyaan.');
    }
}
