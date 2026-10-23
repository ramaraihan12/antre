<?php

namespace App\Queue;

use App\Enums\TicketStatus;
use App\Models\Service;
use App\Models\Ticket;

class WaitEstimator
{
    /** How many finished tickets the rolling average looks at. */
    public const WINDOW = 20;

    /** Average minutes per ticket, from the last 20 finished tickets of the service. */
    public function averageMinutes(Service $service): float
    {
        $durations = Ticket::query()
            ->where('service_id', $service->id)
            ->where('status', TicketStatus::Done)
            ->whereNotNull('called_at')
            ->whereNotNull('done_at')
            ->orderByDesc('done_at')
            ->orderByDesc('id')
            ->limit(self::WINDOW)
            ->get(['called_at', 'done_at'])
            ->map(fn (Ticket $t) => max(0, $t->done_at->getTimestamp() - $t->called_at->getTimestamp()));

        if ($durations->isEmpty()) {
            return (float) $service->avg_duration_minutes;
        }

        return round($durations->avg() / 60, 2);
    }

    /** Waiting tickets of the same service that will be called before this one. */
    public function ticketsAhead(Ticket $ticket): int
    {
        return Ticket::query()
            ->where('branch_id', $ticket->branch_id)
            ->where('service_id', $ticket->service_id)
            ->whereDate('queue_date', $ticket->queue_date)
            ->where('status', TicketStatus::Waiting)
            ->where(function ($q) use ($ticket) {
                $q->where('is_priority', '>', $ticket->is_priority)
                    ->orWhere(fn ($same) => $same->where('is_priority', $ticket->is_priority)->where('id', '<', $ticket->id));
            })
            ->count();
    }

    /**
     * Position and wait for a ticket. Position 0 means it is being called now.
     * The wait assumes every counter that serves the service works at the average pace.
     *
     * @return array{position: int, ahead: int, minutes: int|null, average_minutes: float}
     */
    public function forTicket(Ticket $ticket): array
    {
        $service = $ticket->service;
        $average = $this->averageMinutes($service);

        if ($ticket->status !== TicketStatus::Waiting) {
            return ['position' => 0, 'ahead' => 0, 'minutes' => null, 'average_minutes' => $average];
        }

        $ahead = $this->ticketsAhead($ticket);
        $counters = max(1, $service->counters()->count());

        return [
            'position' => $ahead + 1,
            'ahead' => $ahead,
            'minutes' => (int) ceil($ahead * $average / $counters),
            'average_minutes' => $average,
        ];
    }
}
