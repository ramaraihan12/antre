<?php

namespace App\Queue;

use App\Enums\TicketStatus;
use App\Exceptions\QueueException;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\TicketCall;
use Illuminate\Support\Facades\DB;

/**
 * What a staff member does at a counter. Every method runs in one transaction and
 * takes a row lock first, so two clicks or two counters cannot race each other.
 */
class QueueDesk
{
    public function __construct(private readonly QueueBroadcaster $broadcaster) {}

    /**
     * Claims the next waiting ticket for this counter, or returns null when nobody is waiting.
     *
     * On Postgres the ticket is claimed with FOR UPDATE SKIP LOCKED: a counter that finds the
     * best ticket locked by another counter moves on to the next one instead of waiting.
     */
    public function callNext(Counter $counter): ?Ticket
    {
        $ticket = DB::transaction(function () use ($counter): ?Ticket {
            // Serialises repeated clicks from the same counter.
            Counter::query()->whereKey($counter->id)->lockForUpdate()->firstOrFail();

            $branch = $counter->branch;

            // A ticket left called on an earlier day does not block the counter today.
            $current = Ticket::query()
                ->where('counter_id', $counter->id)
                ->whereDate('queue_date', $branch->today())
                ->where('status', TicketStatus::Called)
                ->first();
            if ($current !== null) {
                throw QueueException::counterBusy($current->code);
            }

            $serviceIds = $counter->services()->pluck('services.id');

            $next = Ticket::query()
                ->where('branch_id', $counter->branch_id)
                ->whereDate('queue_date', $branch->today())
                ->where('status', TicketStatus::Waiting)
                ->whereIn('service_id', $serviceIds)
                ->inCallingOrder()
                ->limit(1)
                ->lock(DB::getDriverName() === 'pgsql' ? 'for update skip locked' : false)
                ->first();

            if ($next === null) {
                return null;
            }

            $next->update([
                'status' => TicketStatus::Called,
                'counter_id' => $counter->id,
                'called_at' => now(),
                'call_count' => 1,
            ]);
            $this->logCall($next, $counter, 'call');

            return $next;
        });

        if ($ticket !== null) {
            $this->broadcaster->ticketCalled($ticket, $counter, recall: false);
        }

        return $ticket;
    }

    public function recall(Ticket $ticket): Ticket
    {
        $ticket = DB::transaction(function () use ($ticket): Ticket {
            $locked = $this->lockInState($ticket, TicketStatus::Called);
            $locked->increment('call_count');
            $this->logCall($locked, $locked->counter, 'recall');

            return $locked;
        });

        $this->broadcaster->ticketCalled($ticket, $ticket->counter, recall: true);

        return $ticket;
    }

    /** The customer did not show up. */
    public function skip(Ticket $ticket): Ticket
    {
        return $this->finish($ticket, TicketStatus::Skipped);
    }

    public function done(Ticket $ticket): Ticket
    {
        return $this->finish($ticket, TicketStatus::Done);
    }

    /** A customer leaves the queue before being called. */
    public function cancel(Ticket $ticket): Ticket
    {
        $ticket = DB::transaction(function () use ($ticket): Ticket {
            $locked = $this->lockInState($ticket, TicketStatus::Waiting);
            $locked->update(['status' => TicketStatus::Cancelled]);

            return $locked;
        });

        $this->broadcaster->queueChanged($ticket->branch_id, "Tiket {$ticket->code} dibatalkan");

        return $ticket;
    }

    private function finish(Ticket $ticket, TicketStatus $to): Ticket
    {
        $ticket = DB::transaction(function () use ($ticket, $to): Ticket {
            $locked = $this->lockInState($ticket, TicketStatus::Called);
            $locked->update([
                'status' => $to,
                'done_at' => $to === TicketStatus::Done ? now() : null,
            ]);

            return $locked;
        });

        $this->broadcaster->counterFreed($ticket, $to);

        return $ticket;
    }

    private function lockInState(Ticket $ticket, TicketStatus $expected): Ticket
    {
        $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== $expected) {
            throw QueueException::wrongState($locked->code, $locked->status->value);
        }

        return $locked;
    }

    private function logCall(Ticket $ticket, Counter $counter, string $kind): void
    {
        TicketCall::create([
            'business_id' => $ticket->business_id,
            'branch_id' => $ticket->branch_id,
            'ticket_id' => $ticket->id,
            'counter_id' => $counter->id,
            'kind' => $kind,
        ]);
    }
}
