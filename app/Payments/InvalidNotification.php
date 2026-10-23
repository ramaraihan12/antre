<?php

namespace App\Payments;

use RuntimeException;

class InvalidNotification extends RuntimeException
{
    public static function malformed(string $field): self
    {
        return new self("Missing or invalid field: $field", 400);
    }

    public static function badSignature(): self
    {
        return new self('Invalid signature.', 403);
    }
}
