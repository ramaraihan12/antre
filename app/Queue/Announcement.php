<?php

namespace App\Queue;

/** Builds the sentence the TV display speaks, in Indonesian. */
final class Announcement
{
    private const UNITS = ['nol', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan'];

    public static function forCall(string $letter, int $number, int $counterNumber): string
    {
        return sprintf(
            'Nomor antrean %s %s, silakan ke loket %s',
            $letter,
            self::words($number),
            self::words($counterNumber),
        );
    }

    /**
     * Spells a number the way it is read aloud: 12 is "dua belas", 100 is "seratus", 1000 is "seribu".
     * Numbers beyond 9999 (or negative) are read digit by digit, so a call never fails on a long number.
     */
    public static function words(int $n): string
    {
        if ($n < 0 || $n > 9999) {
            return implode(' ', array_map(
                fn (string $digit) => $digit === '-' ? 'minus' : self::UNITS[(int) $digit],
                str_split((string) $n),
            ));
        }

        if ($n >= 1000) {
            $rest = $n % 1000;
            $head = intdiv($n, 1000) === 1 ? 'seribu' : self::UNITS[intdiv($n, 1000)].' ribu';

            return $head.($rest ? ' '.self::words($rest) : '');
        }

        if ($n < 10) {
            return self::UNITS[$n];
        }

        if ($n === 10) {
            return 'sepuluh';
        }

        if ($n === 11) {
            return 'sebelas';
        }

        if ($n < 20) {
            return self::UNITS[$n - 10].' belas';
        }

        if ($n < 100) {
            $rest = $n % 10;

            return self::UNITS[intdiv($n, 10)].' puluh'.($rest ? ' '.self::UNITS[$rest] : '');
        }

        $rest = $n % 100;
        $head = intdiv($n, 100) === 1 ? 'seratus' : self::UNITS[intdiv($n, 100)].' ratus';

        return $head.($rest ? ' '.self::words($rest) : '');
    }
}
