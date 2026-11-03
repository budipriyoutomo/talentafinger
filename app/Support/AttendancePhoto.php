<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Aturan bersama untuk absen yang dikirim karyawan dengan foto + lokasi
 * (absen foto /absen dan absen QR /absen/qr): batas ukuran, validasi, dan
 * tempat penyimpanan foto. Satu sumber supaya kedua jalur tak berbeda pendapat.
 */
final class AttendancePhoto
{
    /** Disk lama; foto sebelum pindah ke Object Storage masih ada di sini. */
    public const LEGACY_DISK = 'local';

    /** Batas ukuran foto dari sisi aplikasi (KB); batas PHP bisa lebih kecil. */
    public const MAX_KB = 8192;

    /**
     * Ukuran foto maksimal yang BENAR-BENAR bisa diterima (KB): yang terkecil
     * dari batas aplikasi dan batas upload PHP (upload_max_filesize/post_max_size).
     * Dikirim juga ke halaman supaya browser bisa menolak lebih awal.
     */
    public static function maxKb(): int
    {
        $limits = array_filter([
            self::MAX_KB,
            self::iniBytes('upload_max_filesize') / 1024,
            self::iniBytes('post_max_size') / 1024,
        ]);

        return (int) floor(min($limits));
    }

    public static function mbLabel(): string
    {
        return rtrim(rtrim(number_format(self::maxKb() / 1024, 1), '0'), '.') . 'MB';
    }

    /** Aturan validasi foto + lokasi. */
    public static function rules(): array
    {
        $maxKb = self::maxKb();

        return [
            'photo' => "required|image|mimes:jpeg,jpg,png,webp|max:{$maxKb}",
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
        ];
    }

    public static function messages(): array
    {
        return [
            'photo.required' => 'Foto wajib diambil.',
            'photo.image' => 'File harus berupa foto.',
            'photo.max' => 'Ukuran foto maksimal ' . self::mbLabel() . '.',
            'latitude.required' => 'Lokasi tidak terbaca. Izinkan akses lokasi di browser.',
            'longitude.required' => 'Lokasi tidak terbaca. Izinkan akses lokasi di browser.',
        ];
    }

    /**
     * Upload yang ditolak PHP (melebihi upload_max_filesize) tetap muncul
     * sebagai file tak valid; tanpa cek ini validasi 'required' memberi pesan
     * menyesatkan "Foto wajib diambil" padahal fotonya ada, cuma kebesaran.
     */
    public static function assertUploadValid(Request $request): void
    {
        $upload = $request->file('photo');

        if ($upload && ! $upload->isValid()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Foto terlalu besar untuk diunggah (maks ' . self::mbLabel() . '). Ambil ulang foto.',
            ], 422));
        }
    }

    /** Disk Object Storage IDCloudHost (config/filesystems.php). */
    public const S3_DISK = 'idcloudhost';

    /** Field disk S3 yang bisa ditimpa dari menu Pengaturan (key setting = path config). */
    private const S3_FIELDS = ['endpoint', 'region', 'bucket', 'key', 'secret', 'use_path_style_endpoint'];

    /**
     * Disk PRIVAT tujuan foto baru: foto hanya bisa diambil lewat route photo()
     * yang dijaga policy.
     */
    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(self::diskName());
    }

    /**
     * Nama disk aktif. Saklar di menu Pengaturan (attendance.photo_s3_enabled)
     * menang atas .env (ATTENDANCE_PHOTO_DISK). Object Storage yang dipilih tapi
     * kredensialnya belum lengkap jatuh ke disk lokal, supaya absen karyawan tak
     * gagal hanya karena setting setengah jadi.
     */
    public static function diskName(): string
    {
        $toggle = Setting::value('attendance.photo_s3_enabled');
        $name = $toggle === null
            ? config('adms.photo_disk', self::LEGACY_DISK)
            : ($toggle ? self::S3_DISK : self::LEGACY_DISK);

        if ($name !== self::S3_DISK) {
            return $name;
        }

        $config = self::applyS3Settings();

        if (blank($config['bucket'] ?? null) || blank($config['key'] ?? null) || blank($config['secret'] ?? null)) {
            Log::warning('Object Storage foto absen dipilih tapi bucket/access key/secret belum diisi; foto disimpan di disk lokal.');

            return self::LEGACY_DISK;
        }

        return self::S3_DISK;
    }

    /**
     * Tumpuk setting dari DB ke config disk S3. Disk yang sudah ter-resolve
     * dibuang hanya bila config-nya berubah (worker queue yang hidup lama ikut
     * memakai kredensial baru; Storage::fake di test tidak tertimpa).
     */
    private static function applyS3Settings(): array
    {
        $base = 'filesystems.disks.' . self::S3_DISK;
        $current = config($base, []);
        $next = self::s3Config();

        if ($next !== $current) {
            config([$base => $next]);
            Storage::forgetDisk(self::S3_DISK);
        }

        return $next;
    }

    /**
     * Config disk S3 = config/.env ditimpa setting DB, lalu ditimpa $overrides
     * (nilai form yang belum disimpan, untuk Tes Koneksi). Override kosong diabaikan.
     */
    public static function s3Config(array $overrides = []): array
    {
        $base = 'filesystems.disks.' . self::S3_DISK;
        $config = config($base, []);

        foreach (self::S3_FIELDS as $field) {
            $config[$field] = Setting::value("{$base}.{$field}", $config[$field] ?? null);

            if (array_key_exists($field, $overrides) && ! blank($overrides[$field])) {
                $config[$field] = $overrides[$field];
            }
        }

        return $config;
    }

    /**
     * Uji kredensial Object Storage: tulis, baca, lalu hapus satu file kecil.
     * Melempar exception berisi pesan dari server S3 bila gagal.
     */
    public static function testS3(array $overrides = []): void
    {
        $config = self::s3Config($overrides);

        foreach (['endpoint' => 'Endpoint', 'bucket' => 'Nama Bucket', 'key' => 'Access Key', 'secret' => 'Secret Key'] as $field => $label) {
            if (blank($config[$field] ?? null)) {
                throw new RuntimeException("{$label} belum diisi.");
            }
        }

        // Batas waktu pendek: endpoint salah jangan sampai menggantung request.
        $disk = Storage::build(['throw' => true, 'http' => ['connect_timeout' => 5, 'timeout' => 15]] + $config);
        $path = 'attendance-photos/.connection-test-' . bin2hex(random_bytes(6)) . '.txt';
        $content = 'tes koneksi ' . now()->toIso8601String();

        $disk->put($path, $content);

        try {
            if ($disk->get($path) !== $content) {
                throw new RuntimeException('File tes tersimpan tetapi isinya berbeda saat dibaca ulang.');
            }
        } finally {
            $disk->delete($path);
        }
    }

    /** Simpan foto ke disk privat, dikelompokkan per bulan. Mengembalikan path. */
    public static function store(UploadedFile $photo): string
    {
        $path = self::disk()->putFile('attendance-photos/' . now()->format('Y/m'), $photo);

        if ($path === false) {
            throw new RuntimeException('Gagal menyimpan foto absen ke storage.');
        }

        return $path;
    }

    public static function delete(string $path): void
    {
        self::disk()->delete($path);
    }

    /**
     * Disk yang benar-benar memegang file ini: disk aktif dulu, lalu disk lama
     * (foto sebelum migrasi ke Object Storage). Null bila tak ditemukan.
     */
    public static function locate(?string $path): ?FilesystemAdapter
    {
        if (! $path) {
            return null;
        }

        foreach (array_unique([self::diskName(), self::LEGACY_DISK]) as $name) {
            $disk = Storage::disk($name);
            if ($disk->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    /** Nilai ini PHP seperti "2M" -> byte (0 = tak terbatas). */
    private static function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
