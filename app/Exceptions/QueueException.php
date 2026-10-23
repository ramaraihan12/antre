<?php

namespace App\Exceptions;

use RuntimeException;

/** A queue action that cannot happen in the current state. The message is safe to show to staff. */
class QueueException extends RuntimeException
{
    public static function counterBusy(string $code): self
    {
        return new self("Selesaikan dulu nomor $code sebelum memanggil berikutnya.");
    }

    public static function wrongState(string $code, string $status): self
    {
        return new self("Nomor $code berstatus $status, aksi ini tidak bisa dilakukan.");
    }

    public static function serviceUnavailable(): self
    {
        return new self('Layanan ini sedang tidak tersedia.');
    }
}
