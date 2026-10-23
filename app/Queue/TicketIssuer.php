<?php

namespace App\Queue;

use App\Exceptions\QueueException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Service;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class TicketIssuer
{
    public function __construct(private readonly QueueBroadcaster $broadcaster) {}

    public function issue(Branch $branch, Service $service, ?Booking $booking = null, bool $priority = false): Ticket
    {
        if ($service->branch_id !== $branch->id || ! $service->is_active) {
            throw QueueException::serviceUnavailable();
        }

        $ticket = DB::transaction(function () use ($branch, $service, $booking, $priority): Ticket {
            // Numbers are handed out one at a time per service. The unique index on
            // (branch, service, date, number) is the backstop if this lock is ever skipped.
            Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

            $date = $branch->today();
            $last = (int) Ticket::query()
                ->where('branch_id', $branch->id)
                ->where('service_id', $service->id)
                ->whereDate('queue_date', $date)
                ->max('number');

            $number = $last + 1;

            return Ticket::create([
                'business_id' => $branch->business_id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'booking_id' => $booking?->id,
                'queue_date' => $date,
                'number' => $number,
                'code' => Ticket::formatCode($service->code, $number),
                'is_priority' => $priority,
            ]);
        });

        $this->broadcaster->queueChanged($branch->id, "Tiket {$ticket->code} masuk antrean");

        return $ticket;
    }
}
