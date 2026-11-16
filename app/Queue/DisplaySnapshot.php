<?php

namespace App\Queue;

use App\Enums\TicketStatus;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\TicketCall;
use Illuminate\Support\Facades\DB;

/**
 * What the TV display shows for a branch: one number per counter and the last five calls.
 *
 * The version is branches.display_version, which QueueDesk raises inside the transaction of every
 * change the display can show, to the larger of the old value plus one and the clock in
 * milliseconds. It only goes up, no two changes share a number, and it stays above whatever an
 * open TV saw before a database rebuild, so the TV can tell which of two snapshots is newer.
 *
 * Version and content must describe one moment. QueueDesk builds the snapshot inside the change's
 * own transaction, right after the bump, while it holds the branch row lock. Called on its own
 * (the page load, for()) it reads both in one REPEATABLE READ transaction on Postgres.
 */
class DisplaySnapshot
{
    public const RECENT = 5;

    /**
     * @return array{counters: list<array{number: int, label: string, code: string|null}>, recent: list<array{code: string, counter: string, kind: string}>, version: int, built_at: int}
     */
    public function for(Branch $branch): array
    {
        // Already inside someone else's transaction the isolation level cannot be changed, so
        // this falls back to plain reads at the caller's level. Do not call for() inside a
        // transaction; read at one point in time yourself (QueueDesk uses inChange() for that).
        if (DB::transactionLevel() > 0) {
            return $this->build($branch);
        }

        return DB::transaction(function () use ($branch): array {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('set transaction isolation level repeatable read read only');
            }

            return $this->build($branch);
        });
    }

    /**
     * The snapshot of a change, built inside that change's transaction right after the version
     * bump, while the branch row lock is held. Only QueueDesk calls this.
     *
     * @return array{counters: list<array{number: int, label: string, code: string|null}>, recent: list<array{code: string, counter: string, kind: string}>, version: int, built_at: int}
     */
    public function inChange(Branch $branch): array
    {
        return $this->build($branch);
    }

    /** @return array{counters: list<array{number: int, label: string, code: string|null}>, recent: list<array{code: string, counter: string, kind: string}>, version: int, built_at: int} */
    private function build(Branch $branch): array
    {
        $version = (int) DB::table('branches')->where('id', $branch->id)->value('display_version');

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

        return ['counters' => $counters, 'recent' => $recent, 'version' => $version, 'built_at' => (int) floor(microtime(true) * 1000)];
    }
}
