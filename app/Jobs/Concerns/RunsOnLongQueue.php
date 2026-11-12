<?php

namespace App\Jobs\Concerns;

/**
 * Job berdurasi panjang ($timeout sampai 3600 detik) diarahkan ke antrean 'long'
 * di koneksi 'database-long' (retry_after > $timeout, lihat config/queue.php),
 * yang diproses worker tersendiri. Dengan begitu email registrasi dan pengiriman
 * absensi ke Talenta tidak ikut tertahan di belakang job massal.
 *
 * Panggil useLongQueue() di constructor job.
 */
trait RunsOnLongQueue
{
    protected function useLongQueue(): void
    {
        // Hanya bila antrean memang di database. Driver lain (sync saat test/dev,
        // redis) dibiarkan di koneksi default supaya job tetap jalan.
        if (config('queue.default') === 'database') {
            $this->onConnection('database-long');
        }

        $this->onQueue('long');
    }
}
