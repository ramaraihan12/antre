<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Waiting = 'waiting';
    case Called = 'called';
    case Done = 'done';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Waiting || $this === self::Called;
    }
}
