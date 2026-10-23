<?php

namespace App\Queue;

use App\Enums\TicketStatus;
use App\Events\BookingChanged;
use App\Events\DisplayUpdated;
use App\Events\QueueChanged;
use App\Events\StaffQueueUpdated;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pushes queue changes to the browsers over Reverb. A dead websocket server must never
 * make a staff action fail, so errors are logged and swallowed here.
 */
class QueueBroadcaster
{
    public function __construct(private readonly DisplaySnapshot $snapshots) {}

    public function ticketCalled(Ticket $ticket, Counter $counter, bool $recall): void
    {
        $announcement = [
            'id' => (string) Str::ulid(),
            'code' => $ticket->code,
            'text' => Announcement::forCall($ticket->service->code, $ticket->number, $counter->number),
        ];

        $this->safely(function () use ($ticket, $announcement) {
            $this->broadcastDisplay($ticket->branch_id, $announcement);
        });

        $verb = $recall ? 'dipanggil ulang' : 'dipanggil';
        $this->queueChanged($ticket->branch_id, "{$ticket->code} $verb di {$counter->label()}");
    }

    public function counterFreed(Ticket $ticket, TicketStatus $outcome): void
    {
        $this->safely(fn () => $this->broadcastDisplay($ticket->branch_id, null));

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

    /** @param  array{id: string, code: string, text: string}|null  $announcement */
    private function broadcastDisplay(int $branchId, ?array $announcement): void
    {
        $branch = Branch::query()->findOrFail($branchId);

        DisplayUpdated::dispatch($branchId, $this->snapshots->for($branch), $announcement);
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
