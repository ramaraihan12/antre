<?php

use App\Enums\TicketStatus;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\TicketCall;
use Symfony\Component\Process\Process;

/*
 * These tests start real PHP processes that talk to the same Postgres database, because
 * the guarantee being tested lives in the database lock, not in PHP.
 */
beforeEach(function () {
    if (! getenv('TEST_DATABASE_URL')) {
        $this->markTestSkipped('Set TEST_DATABASE_URL to a Postgres database to run the concurrency tests.');
    }
});

/**
 * @param  list<int>  $counterIds
 * @return list<Process>
 */
function startWorkers(array $counterIds, int $think = 0): array
{
    $startAt = microtime(true) + 1.5;

    return array_map(function (int $id) use ($startAt, $think) {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'antre:simulate-worker', (string) $id, "--start-at=$startAt", "--think=$think"],
            base_path(),
            ['BROADCAST_CONNECTION' => 'null'],
            timeout: 120,
        );
        $process->start();

        return $process;
    }, $counterIds);
}

it('never gives the same ticket to two counters calling at the same time', function () {
    $s = scenario();
    $counters = [$s->c1, $s->c2];
    foreach (range(3, 6) as $number) {
        $extra = Counter::factory()->for($s->branch)->create(['number' => $number]);
        $extra->services()->attach([$s->a->id, $s->b->id]);
        $counters[] = $extra;
    }
    $s->c2->services()->attach($s->b->id);

    foreach (range(1, 120) as $i) {
        issue($s->branch, $i % 3 === 0 ? $s->b : $s->a, priority: $i % 17 === 0);
    }

    $workers = startWorkers(array_map(fn (Counter $c) => $c->id, $counters), think: 2);
    foreach ($workers as $worker) {
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
    }

    $calledByWorkers = collect($workers)
        ->flatMap(fn (Process $p) => preg_split('/\R/', trim($p->getOutput())))
        ->filter()
        ->map(fn (string $line) => (int) explode(' ', $line)[0]);

    expect($calledByWorkers)->toHaveCount(120)
        ->and($calledByWorkers->unique())->toHaveCount(120)
        ->and(Ticket::where('status', TicketStatus::Done)->count())->toBe(120)
        ->and(TicketCall::where('kind', 'call')->count())->toBe(120)
        ->and(TicketCall::selectRaw('ticket_id, count(*) as n')->groupBy('ticket_id')->havingRaw('count(*) > 1')->get())->toBeEmpty();

    // More than one process really did work: nobody sat out the whole run.
    $callsPerWorker = collect($workers)->map(fn (Process $p) => count(array_filter(preg_split('/\R/', trim($p->getOutput())))));
    expect($callsPerWorker->filter(fn (int $n) => $n > 0)->count())->toBeGreaterThan(1);
});

it('skips a ticket another transaction has locked instead of waiting for it', function () {
    $s = scenario();
    $first = issue($s->branch, $s->a);
    $second = issue($s->branch, $s->a);

    // This test holds the lock on the first ticket, like a counter in the middle of calling it.
    DB::beginTransaction();
    Ticket::query()->whereKey($first->id)->lockForUpdate()->firstOrFail();

    try {
        $worker = startWorkers([$s->c2->id])[0];
        $worker->setTimeout(20);
        $started = microtime(true);
        $worker->wait();
        $elapsed = microtime(true) - $started;
    } finally {
        DB::rollBack();
    }

    $called = array_filter(preg_split('/\R/', trim($worker->getOutput())));

    // The worker took the second ticket and then found nothing it could claim. A plain
    // FOR UPDATE would have blocked on the locked first ticket until the timeout.
    expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput())
        ->and($called)->toHaveCount(1)
        ->and((int) explode(' ', array_values($called)[0])[0])->toBe($second->id)
        ->and($first->fresh()->status)->toBe(TicketStatus::Waiting)
        ->and($elapsed)->toBeLessThan(15.0);
});
