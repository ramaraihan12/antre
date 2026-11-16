<?php

namespace App\Queue;

use App\Enums\TicketStatus;
use App\Events\BookingChanged;
use App\Events\DisplayUpdated;
use App\Events\QueueChanged;
use App\Events\StaffQueueUpdated;
use App\Models\Counter;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pushes queue changes to the browsers over Reverb or Ably. A dead websocket server must
 * never make a staff action fail, so errors are logged and swallowed here.
 */
class QueueBroadcaster
{
    /**
     * Every method that pushes to the TV takes the snapshot the change built inside its own
     * transaction and sends that exact payload after commit. Nothing is rebuilt here, so a
     * rolled-back change broadcasts nothing and a committed one broadcasts one snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function ticketCalled(Ticket $ticket, Counter $counter, array $snapshot, bool $recall): void
    {
        $this->safely(function () use ($ticket, $counter, $snapshot) {
            DisplayUpdated::dispatch($ticket->branch_id, $snapshot, [
                'id' => (string) Str::ulid(),
                'code' => $ticket->code,
                'text' => Announcement::forCall($ticket->service->code, $ticket->number, $counter->number),
            ]);
        });

        $verb = $recall ? 'dipanggil ulang' : 'dipanggil';
        $this->queueChanged($ticket->branch_id, "{$ticket->code} $verb di {$counter->label()}");
    }

    /** @param  array<string, mixed>  $snapshot */
    public function counterFreed(Ticket $ticket, TicketStatus $outcome, array $snapshot): void
    {
        $this->safely(fn () => DisplayUpdated::dispatch($ticket->branch_id, $snapshot, null));

        $what = $outcome === TicketStatus::Done ? 'selesai' : 'tidak hadir';
        $this->queueChanged($ticket->branch_id, "{$ticket->code} $what");
    }

    public function queueChanged(int $branchId, string $summary): void
    {
        $this->safely(function () use ($branchId, $summary) {
            QueueChanged::dispatch($branchId);
            StaffQueueUpdated::dispatch($branchId, $summary);
        });
    }

    public function bookingChanged(string $bookingPublicId): void
    {
        $this->safely(fn () => BookingChanged::dispatch($bookingPublicId));
    }

    /** Runs after the surrounding transaction commits, so listeners never read stale rows. */
    private function safely(callable $callback): void
    {
        DB::afterCommit(function () use ($callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                Log::warning('Broadcast failed: '.$e->getMessage());
            }
        });
    }
}
