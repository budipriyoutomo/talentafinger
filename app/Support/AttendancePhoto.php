<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Aturan bersama untuk absen yang dikirim karyawan dengan foto + lokasi
 * (absen foto /absen dan absen QR /absen/qr): batas ukuran, validasi, dan
 * tempat penyimpanan foto. Satu sumber supaya kedua jalur tak berbeda pendapat.
 */
final class AttendancePhoto
{
    /** Disk PRIVAT: foto hanya bisa diambil lewat route photo() yang dijaga policy. */
    public const DISK = 'local';

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

    /** Simpan foto ke disk privat, dikelompokkan per bulan. Mengembalikan path. */
    public static function store(UploadedFile $photo): string
    {
        return $photo->store('attendance-photos/' . now()->format('Y/m'), self::DISK);
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
