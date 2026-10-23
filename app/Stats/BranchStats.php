<?php

namespace App\Stats;

use App\Enums\TicketStatus;
use App\Models\Branch;
use App\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * Today's numbers for one branch. Computed in PHP from one day of tickets, which is a few
 * hundred rows, so the same code runs on Postgres and on the SQLite used by quick tests.
 */
class BranchStats
{
    /**
     * @return array{
     *     date: string,
     *     issued: int,
     *     served: int,
     *     waiting: int,
     *     no_shows: int,
     *     avg_wait_minutes: float|null,
     *     avg_service_minutes: float|null,
     *     no_show_rate: float|null,
     *     per_hour: list<array{hour: int, label: string, tickets: int}>
     * }
     */
    public function today(Branch $branch): array
    {
        $date = $branch->today();

        $tickets = Ticket::query()
            ->where('branch_id', $branch->id)
            ->whereDate('queue_date', $date)
            ->get(['status', 'created_at', 'called_at', 'done_at']);

        $waits = $tickets->filter(fn (Ticket $t) => $t->called_at !== null)
            ->map(fn (Ticket $t) => max(0, $t->called_at->getTimestamp() - $t->created_at->getTimestamp()));

        $services = $tickets->filter(fn (Ticket $t) => $t->status === TicketStatus::Done && $t->called_at && $t->done_at)
            ->map(fn (Ticket $t) => max(0, $t->done_at->getTimestamp() - $t->called_at->getTimestamp()));

        $served = $tickets->where('status', TicketStatus::Done)->count();
        $noShows = $tickets->where('status', TicketStatus::Skipped)->count();

        return [
            'date' => $date,
            'issued' => $tickets->count(),
            'served' => $served,
            'waiting' => $tickets->where('status', TicketStatus::Waiting)->count(),
            'no_shows' => $noShows,
            'avg_wait_minutes' => $waits->isEmpty() ? null : round($waits->avg() / 60, 1),
            'avg_service_minutes' => $services->isEmpty() ? null : round($services->avg() / 60, 1),
            'no_show_rate' => $served + $noShows === 0 ? null : round($noShows / ($served + $noShows), 3),
            'per_hour' => $this->perHour($branch, $tickets),
        ];
    }

    /**
     * Tickets issued per local hour, across opening hours plus any hour that has tickets.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return list<array{hour: int, label: string, tickets: int}>
     */
    private function perHour(Branch $branch, $tickets): array
    {
        $counts = $tickets->countBy(fn (Ticket $t) => (int) $t->created_at->copy()->setTimezone($branch->timezone)->format('G'));

        $hours = $branch->hoursOn($branch->now());
        $open = $hours ? (int) substr($hours[0], 0, 2) : 9;
        // A branch closing at 17:00 has no ticket hour 17, one closing at 17:30 does.
        $close = $hours ? (int) substr($hours[1], 0, 2) - (substr($hours[1], 3, 2) === '00' ? 1 : 0) : 16;

        $first = min($open, $counts->keys()->min() ?? $open);
        $last = max($close, $counts->keys()->max() ?? $close);

        $out = [];
        for ($hour = $first; $hour <= $last; $hour++) {
            $out[] = ['hour' => $hour, 'label' => sprintf('%02d', $hour), 'tickets' => $counts->get($hour, 0)];
        }

        return $out;
    }
}
