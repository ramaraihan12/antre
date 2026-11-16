<?php

namespace App\Queue;

use App\Enums\TicketStatus;
use App\Exceptions\QueueException;
use App\Models\Branch;
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
    public function __construct(
        private readonly QueueBroadcaster $broadcaster,
        private readonly DisplaySnapshot $snapshots,
    ) {}

    /**
     * Claims the next waiting ticket for this counter, or returns null when nobody is waiting.
     *
     * On Postgres the ticket is claimed with FOR UPDATE SKIP LOCKED: a counter that finds the
     * best ticket locked by another counter moves on to the next one instead of waiting.
     */
    public function callNext(Counter $counter): ?Ticket
    {
        $snapshot = [];
        $ticket = DB::transaction(function () use ($counter, &$snapshot): ?Ticket {
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
            // Take the branch lock before logging the call: the call's id is then handed out
            // under the lock, so per branch the ids follow commit order and the recent list
            // only ever gains entries at its head.
            $this->bump($branch);
            $this->logCall($next, $counter, 'call');
            $snapshot = $this->snapshots->inChange($branch);

            return $next;
        });

        if ($ticket !== null) {
            $this->broadcaster->ticketCalled($ticket, $counter, $snapshot, recall: false);
        }

        return $ticket;
    }

    public function recall(Ticket $ticket): Ticket
    {
        $snapshot = [];
        $ticket = DB::transaction(function () use ($ticket, &$snapshot): Ticket {
            // Same order as callNext: the counter row first, then the ticket, then the branch
            // (in bump). A different order can deadlock against a call from the same counter.
            if ($ticket->counter_id !== null) {
                Counter::query()->whereKey($ticket->counter_id)->lockForUpdate()->first();
            }
            $locked = $this->lockInState($ticket, TicketStatus::Called);
            $locked->increment('call_count');
            $this->bump($locked->branch);
            $this->logCall($locked, $locked->counter, 'recall');
            $snapshot = $this->snapshots->inChange($locked->branch);

            return $locked;
        });

        $this->broadcaster->ticketCalled($ticket, $ticket->counter, $snapshot, recall: true);

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
        $snapshot = [];
        $ticket = DB::transaction(function () use ($ticket, $to, &$snapshot): Ticket {
            $locked = $this->lockInState($ticket, TicketStatus::Called);
            $locked->update([
                'status' => $to,
                'done_at' => $to === TicketStatus::Done ? now() : null,
            ]);
            $this->bump($locked->branch);
            $snapshot = $this->snapshots->inChange($locked->branch);

            return $locked;
        });

        $this->broadcaster->counterFreed($ticket, $to, $snapshot);

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

    /**
     * Gives the change a new display version. The update takes the branch row lock, which is held
     * until commit, so no other change to this branch can slip in between the number and the
     * rows read for the snapshot afterwards. Order inside a change: row changes, bump, log the
     * call (if any), then build the snapshot with DisplaySnapshot::inChange(). The version is
     * one more than the branch had, never below the clock in milliseconds, so it is unique,
     * follows commit order and stays above anything an open TV can still hold after the demo
     * database is rebuilt or a backup is restored.
     *
     * The caller broadcasts the snapshot after commit, as built, instead of building another one
     * from whatever the database holds by then.
     */
    private function bump(Branch $branch): void
    {
        $now = (int) floor(microtime(true) * 1000);
        $pick = DB::connection()->getDriverName() === 'sqlite' ? 'max' : 'greatest';

        DB::update("update branches set display_version = {$pick}(display_version + 1, ?) where id = ?", [$now, $branch->id]);
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
