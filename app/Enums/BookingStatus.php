<?php

namespace App\Enums;

enum BookingStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case CheckedIn = 'checked_in';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * Statuses that hold a slot.
     *
     * @return list<self>
     */
    public static function holdingSlot(): array
    {
        return [self::PendingPayment, self::Paid, self::CheckedIn];
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Menunggu pembayaran',
            self::Paid => 'Sudah dibayar',
            self::CheckedIn => 'Sudah check-in',
            self::Expired => 'Kedaluwarsa',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
