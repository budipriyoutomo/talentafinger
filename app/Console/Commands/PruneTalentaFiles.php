<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Hapus arsip kiriman ke Talenta yang sudah tua.
 *
 * Tiap upload menyimpan salinan CSV + response di storage/app/talenta (lihat
 * MekariTalentaService::importFingerprint) untuk audit. Tanpa pembersihan,
 * folder ini tumbuh terus dan lama-lama memenuhi disk VPS. Umur maksimal
 * diatur lewat setting mekari.file_retention_days (0 = jangan hapus).
 *
 * Dijadwalkan harian (lihat routes/console.php).
 */
class PruneTalentaFiles extends Command
{
    protected $signature = 'talenta:prune-files {--days= : Timpa umur maksimal (hari) dari setting}';

    protected $description = 'Hapus arsip CSV/response kiriman Talenta yang lebih tua dari batas retensi.';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) Setting::value('mekari.file_retention_days', 90);

        if ($days <= 0) {
            $this->info('Retensi arsip Talenta nonaktif (0 hari = simpan selamanya).');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days)->getTimestamp();
        $disk = Storage::disk();
        $deleted = 0;

        foreach ($disk->files('talenta') as $path) {
            if ($disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
                $deleted++;
            }
        }

        $this->info("Arsip Talenta: {$deleted} file lebih tua dari {$days} hari dihapus.");

        return self::SUCCESS;
    }
}
