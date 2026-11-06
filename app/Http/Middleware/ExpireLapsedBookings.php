<?php

namespace App\Http\Middleware;

use App\Booking\BookingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Releases lapsed holds when a booking page or action runs, for hosts where nothing runs between
 * visits. With a scheduler this usually finds nothing to do. The limit is there because each
 * release is also a broadcast, and a visitor is waiting for this request.
 */
class ExpireLapsedBookings
{
    private const PER_REQUEST = 20;

    public function __construct(private readonly BookingService $bookings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->bookings->expireDue(self::PER_REQUEST);

        return $next($request);
    }
}
