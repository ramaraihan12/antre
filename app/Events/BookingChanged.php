<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A nudge for one booking page: the payment status changed, reload it. */
class BookingChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public string $bookingPublicId) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel("booking.{$this->bookingPublicId}")];
    }

    public function broadcastAs(): string
    {
        return 'booking.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [];
    }
}
