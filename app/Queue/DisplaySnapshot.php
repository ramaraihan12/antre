<?php

namespace App\Queue;

use App\Enums\TicketStatus;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\TicketCall;

/** What the TV display shows for a branch: one number per counter and the last five calls. */
class DisplaySnapshot
{
    public const RECENT = 5;

    /**
     * @return array{counters: list<array{number: int, label: string, code: string|null}>, recent: list<array{code: string, counter: string, kind: string}>, version: int}
     */
    public function for(Branch $branch): array
    {
        $active = Ticket::query()
            ->where('branch_id', $branch->id)
            ->whereDate('queue_date', $branch->today())
            ->where('status', TicketStatus::Called)
            ->get()
            ->keyBy('counter_id');

        $counters = Counter::query()
            ->where('branch_id', $branch->id)
            ->orderBy('number')
            ->get()
            ->map(fn (Counter $counter) => [
                'number' => $counter->number,
                'label' => $counter->label(),
                'code' => $active->get($counter->id)?->code,
            ])
            ->values()
            ->all();

        $recent = TicketCall::query()
            ->where('branch_id', $branch->id)
            ->whereHas('ticket', fn ($query) => $query->whereDate('queue_date', $branch->today()))
            ->with(['ticket', 'counter'])
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get()
            ->map(fn (TicketCall $call) => [
                'code' => $call->ticket->code,
                'counter' => $call->counter->label(),
                'kind' => $call->kind,
            ])
            ->values()
            ->all();

        return ['counters' => $counters, 'recent' => $recent, 'version' => (int) floor(microtime(true) * 1000)];
    }
}
