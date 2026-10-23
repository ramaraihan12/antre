<?php

use App\Enums\TicketStatus;
use App\Models\Service;
use App\Models\Ticket;
use App\Queue\WaitEstimator;
use Illuminate\Support\Carbon;

/** Inserts a finished ticket that took $minutes at the counter. */
function finished(Service $service, int $number, float $minutes, Carbon $doneAt): Ticket
{
    return Ticket::create([
        'business_id' => $service->business_id,
        'branch_id' => $service->branch_id,
        'service_id' => $service->id,
        'queue_date' => $doneAt->toDateString(),
        'number' => $number,
        'code' => Ticket::formatCode($service->code, $number),
        'status' => TicketStatus::Done,
        'called_at' => $doneAt->copy()->subSeconds((int) ($minutes * 60)),
        'done_at' => $doneAt,
    ]);
}

afterEach(fn () => Carbon::setTestNow());

it('falls back to the configured duration when nothing has finished yet', function () {
    $s = scenario();
    $s->a->update(['avg_duration_minutes' => 12]);

    expect(app(WaitEstimator::class)->averageMinutes($s->a->fresh()))->toBe(12.0);
});

it('averages only the last 20 finished tickets', function () {
    $s = scenario();
    $now = Carbon::parse('2026-05-04 10:00:00', 'UTC');

    // Five old, slow tickets (60 minutes) and then twenty quick ones (10 minutes).
    foreach (range(1, 5) as $i) {
        finished($s->a, $i, 60, $now->copy()->subHours(5)->addMinutes($i));
    }
    foreach (range(6, 25) as $i) {
        finished($s->a, $i, 10, $now->copy()->subHours(2)->addMinutes($i));
    }

    expect(app(WaitEstimator::class)->averageMinutes($s->a))->toBe(10.0);
});

it('ignores finished tickets of other services', function () {
    $s = scenario();
    finished($s->b, 1, 40, now());

    expect(app(WaitEstimator::class)->averageMinutes($s->a))->toBe(15.0);
});

it('counts only tickets that will be called first', function () {
    $s = scenario();
    $first = issue($s->branch, $s->a);
    $second = issue($s->branch, $s->a);
    $priority = issue($s->branch, $s->a, priority: true);
    issue($s->branch, $s->b);

    $estimator = app(WaitEstimator::class);

    expect($estimator->ticketsAhead($priority))->toBe(0)
        ->and($estimator->ticketsAhead($first))->toBe(1)
        ->and($estimator->ticketsAhead($second))->toBe(2);
});

it('estimates wait from tickets ahead, the average and the counters on the service', function () {
    $s = scenario();
    $now = Carbon::parse('2026-05-04 10:00:00', 'UTC');
    Carbon::setTestNow($now);
    finished($s->a, 90, 10, $now->copy()->subHour());

    issue($s->branch, $s->a);
    issue($s->branch, $s->a);
    issue($s->branch, $s->a);
    $fourth = issue($s->branch, $s->a);

    // Three ahead, 10 minutes each, two counters serve A: ceil(30 / 2) = 15.
    $estimate = app(WaitEstimator::class)->forTicket($fourth->load('service'));

    expect($estimate)->toMatchArray(['position' => 4, 'ahead' => 3, 'minutes' => 15, 'average_minutes' => 10.0]);
});

it('reports position zero once a ticket is called', function () {
    $s = scenario();
    $ticket = issue($s->branch, $s->a);
    $ticket->update(['status' => TicketStatus::Called]);

    expect(app(WaitEstimator::class)->forTicket($ticket->load('service')))
        ->toMatchArray(['position' => 0, 'minutes' => null]);
});
