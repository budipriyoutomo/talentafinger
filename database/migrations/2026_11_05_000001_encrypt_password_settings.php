<?php

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Enkripsi setting bertipe 'password' (mekari.client_secret,
 * mekari.fingerprint_token, filesystems.disks.idcloudhost.secret) yang masih
 * plaintext. Kuncinya APP_KEY: simpan APP_KEY di password manager sebelum deploy.
 *
 * Aman diulang: nilai yang sudah bisa didekripsi dianggap sudah terenkripsi
 * dan dilewati. Hanya mengubah kolom value, tidak menghapus baris.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->eachSecret(function (string $value) {
            return $this->tryDecrypt($value) === null ? Crypt::encryptString($value) : null;
        });
    }

    public function down(): void
    {
        $this->eachSecret(fn (string $value) => $this->tryDecrypt($value));
    }

    /** @param  callable(string): ?string  $transform  null = biarkan baris apa adanya */
    private function eachSecret(callable $transform): void
    {
        DB::table('settings')
            ->where('type', 'password')
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->orderBy('id')
            ->get(['id', 'value'])
            ->each(function ($row) use ($transform) {
                $next = $transform($row->value);
                if ($next !== null) {
                    DB::table('settings')->where('id', $row->id)->update(['value' => $next]);
                }
            });

        // Cache setting (driver database) masih memegang nilai lama.
        Setting::flushCache();
    }

    private function tryDecrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
};
