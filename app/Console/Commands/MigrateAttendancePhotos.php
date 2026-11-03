<?php

namespace App\Console\Commands;

use App\Support\AttendancePhoto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Pindahkan foto absen lama dari disk lokal (storage/app/private) ke disk foto
 * aktif (AttendancePhoto::diskName(), mis. Object Storage IDCloudHost).
 *
 * Path di attendance_logs.photo_path tidak berubah, jadi tak perlu update DB.
 * Selama belum dipindah, foto lama tetap terbaca lewat fallback
 * AttendancePhoto::locate(). Aman dijalankan ulang: file yang sudah ada di
 * tujuan dilewati.
 */
class MigrateAttendancePhotos extends Command
{
    protected $signature = 'attendance:migrate-photos
        {--delete-local : Hapus file lokal setelah tersalin dan terverifikasi}
        {--dry-run : Hanya tampilkan jumlah file, tanpa menyalin}';

    protected $description = 'Salin foto absen lama dari disk lokal ke disk foto aktif (Object Storage).';

    public function handle(): int
    {
        $target = AttendancePhoto::diskName();

        if ($target === AttendancePhoto::LEGACY_DISK) {
            $this->error('Disk foto aktif masih lokal. Aktifkan Object Storage & lengkapi kredensialnya di menu Pengaturan dulu.');

            return self::FAILURE;
        }

        $source = Storage::disk(AttendancePhoto::LEGACY_DISK);
        $dest = AttendancePhoto::disk();
        $files = $source->allFiles('attendance-photos');

        $this->info(count($files) . " foto ditemukan di disk lokal; tujuan: disk '{$target}'.");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $copied = $skipped = $failed = 0;

        foreach ($files as $path) {
            try {
                if ($dest->exists($path)) {
                    $skipped++;
                } else {
                    $stream = $source->readStream($path);
                    $dest->writeStream($path, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    $copied++;
                }

                if ($this->option('delete-local') && $dest->size($path) === $source->size($path)) {
                    $source->delete($path);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("Gagal: {$path} — {$e->getMessage()}");
            }
        }

        $this->info("Selesai: {$copied} disalin, {$skipped} sudah ada, {$failed} gagal.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
