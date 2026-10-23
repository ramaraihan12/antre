<?php

use App\Enums\TicketStatus;
use App\Exceptions\QueueException;
use App\Models\TicketCall;
use App\Queue\QueueDesk;

beforeEach(fn () => $this->desk = app(QueueDesk::class));

it('calls tickets in arrival order', function () {
    $s = scenario();
    $first = issue($s->branch, $s->a);
    issue($s->branch, $s->a);

    $called = $this->desk->callNext($s->c1);

    expect($called->is($first))->toBeTrue()
        ->and($called->status)->toBe(TicketStatus::Called)
        ->and($called->counter_id)->toBe($s->c1->id)
        ->and($called->called_at)->not->toBeNull()
        ->and($called->call_count)->toBe(1);
});

it('calls priority tickets before earlier walk-ins', function () {
    $s = scenario();
    issue($s->branch, $s->a);
    $priority = issue($s->branch, $s->a, priority: true);

    expect($this->desk->callNext($s->c1)->is($priority))->toBeTrue();
});

it('only calls services the counter works on', function () {
    $s = scenario();
    $b = issue($s->branch, $s->b);

    expect($this->desk->callNext($s->c2))->toBeNull()
        ->and($this->desk->callNext($s->c1)->is($b))->toBeTrue();
});

it('returns null when nobody is waiting', function () {
    $s = scenario();

    expect($this->desk->callNext($s->c1))->toBeNull();
});

it('does not call tickets from an earlier day', function () {
    $s = scenario();
    $old = issue($s->branch, $s->a);
    $old->update(['queue_date' => now($s->branch->timezone)->subDay()->toDateString()]);

    expect($this->desk->callNext($s->c1))->toBeNull();
});

it('does not hand a counter a second ticket until the first is closed', function () {
    $s = scenario();
    issue($s->branch, $s->a);
    issue($s->branch, $s->a);
    $this->desk->callNext($s->c1);

    $this->desk->callNext($s->c1);
})->throws(QueueException::class, 'Selesaikan dulu nomor A-001');

it('lets two counters take different tickets', function () {
    $s = scenario();
    issue($s->branch, $s->a);
    issue($s->branch, $s->a);

    $one = $this->desk->callNext($s->c1);
    $two = $this->desk->callNext($s->c2);

    expect($one->is($two))->toBeFalse();
});

it('recalls the current ticket and logs the call', function () {
    $s = scenario();
    issue($s->branch, $s->a);
    $ticket = $this->desk->callNext($s->c1);

    $this->desk->recall($ticket);

    expect($ticket->fresh()->call_count)->toBe(2)
        ->and(TicketCall::where('ticket_id', $ticket->id)->pluck('kind')->all())->toBe(['call', 'recall']);
});

it('finishes a ticket and frees the counter', function () {
    $s = scenario();
    issue($s->branch, $s->a);
    issue($s->branch, $s->a);
    $ticket = $this->desk->callNext($s->c1);

    $this->desk->done($ticket);

    expect($ticket->fresh()->status)->toBe(TicketStatus::Done)
        ->and($ticket->fresh()->done_at)->not->toBeNull()
        ->and($this->desk->callNext($s->c1)->code)->toBe('A-002');
});

it('marks a no-show as skipped without a finish time', function () {
    $s = scenario();
    issue($s->branch, $s->a);
    $ticket = $this->desk->callNext($s->c1);

    $this->desk->skip($ticket);

    expect($ticket->fresh()->status)->toBe(TicketStatus::Skipped)
        ->and($ticket->fresh()->done_at)->toBeNull();
});

it('rejects actions that do not fit the ticket state', function (string $action) {
    $s = scenario();
    $ticket = issue($s->branch, $s->a);

    $this->desk->{$action}($ticket);
})->with(['recall', 'done', 'skip'])->throws(QueueException::class);

it('does not finish a ticket twice', function () {
    $s = scenario();
    issue($s->branch, $s->a);
    $ticket = $this->desk->callNext($s->c1);
    $this->desk->done($ticket);

    $this->desk->done($ticket);
})->throws(QueueException::class);

it('lets a customer cancel only while waiting', function () {
    $s = scenario();
    $waiting = issue($s->branch, $s->a);
    $this->desk->cancel($waiting);
    expect($waiting->fresh()->status)->toBe(TicketStatus::Cancelled);

    issue($s->branch, $s->a);
    $called = $this->desk->callNext($s->c1);
    $this->desk->cancel($called);
})->throws(QueueException::class);
