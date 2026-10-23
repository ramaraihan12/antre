<?php

namespace App\Console\Commands;

use App\Booking\BookingService;
use Illuminate\Console\Command;

class ExpireBookings extends Command
{
    protected $signature = 'antre:expire-bookings';

    protected $description = 'Release unpaid bookings whose hold has run out';

    public function handle(BookingService $bookings): int
    {
        $count = $bookings->expireDue();

        $this->info("Expired $count booking(s).");

        return self::SUCCESS;
    }
}
