<?php

namespace App\Booking;

use RuntimeException;

class SlotUnavailable extends RuntimeException
{
    public static function taken(): self
    {
        return new self('Jam ini sudah penuh. Pilih jam lain.');
    }

    public static function outsideHours(): self
    {
        return new self('Jam ini di luar jam buka.');
    }

    public static function notBookable(): self
    {
        return new self('Layanan ini belum bisa dipesan online.');
    }
}
