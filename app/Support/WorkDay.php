<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Satu-satunya tempat yang mengubah timestamp absen menjadi tanggal HARI KERJA.
 *
 * Jam pergantian hari kerja diatur lewat setting attendance.workday_cutoff
 * (format HH:MM, default 00:00 = tanggal kalender). Contoh cutoff 05:00: absen
 * 01:30 tanggal 5 dihitung hari kerja tanggal 4, sehingga shift lewat tengah
 * malam tidak terpecah dua hari.
 *
 * Dihitung di PHP, bukan SQL, supaya PostgreSQL (production) dan SQLite (test)
 * pasti memberi hasil yang sama. Timestamp absen adalah jam dinding lokal
 * perangkat, jadi tidak ada konversi zona waktu di sini.
 */
class WorkDay
{
    /** Menit sejak tengah malam untuk cutoff (0 = tanggal kalender). */
    public static function cutoffMinutes(): int
    {
        $value = (string) (Setting::value('attendance.workday_cutoff', '00:00') ?: '00:00');

        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $m)) {
            return 0;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /** Tanggal hari kerja (Y-m-d) untuk sebuah timestamp absen. */
    public static function dateOf(DateTimeInterface|string $timestamp, ?int $cutoffMinutes = null): string
    {
        $cutoff = $cutoffMinutes ?? self::cutoffMinutes();
        $at = self::wallClock($timestamp);

        return $at->subMinutes($cutoff)->toDateString();
    }

    /**
     * Rentang timestamp [mulai, akhir) yang mencakup hari kerja $from..$to.
     *
     * @return array{0: string, 1: string} jam dinding 'Y-m-d H:i:s'
     */
    public static function bounds(string $from, string $to, ?int $cutoffMinutes = null): array
    {
        $cutoff = $cutoffMinutes ?? self::cutoffMinutes();
        $start = CarbonImmutable::parse($from)->startOfDay()->addMinutes($cutoff);
        $end = CarbonImmutable::parse($to)->startOfDay()->addDay()->addMinutes($cutoff);

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /** Timestamp sebagai jam dinding apa adanya (tanpa geser zona waktu). */
    private static function wallClock(DateTimeInterface|string $timestamp): CarbonImmutable
    {
        $text = $timestamp instanceof DateTimeInterface ? $timestamp->format('Y-m-d H:i:s') : (string) $timestamp;

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', substr($text, 0, 19), 'UTC');
    }
}
