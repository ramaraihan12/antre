<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function checkIn(User $user, Booking $booking): bool
    {
        return $user->worksAt($booking->business_id, $booking->branch_id);
    }
}
