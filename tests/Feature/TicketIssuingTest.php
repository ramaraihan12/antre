<?php

use App\Exceptions\QueueException;
use App\Models\Ticket;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

it('numbers tickets per service and pads the code', function () {
    $s = scenario();

    $first = issue($s->branch, $s->a);
    $second = issue($s->branch, $s->a);
    $other = issue($s->branch, $s->b);

    expect($first->code)->toBe('A-001')
        ->and($second->code)->toBe('A-002')
        ->and($other->code)->toBe('B-001')
        ->and($first->public_id)->toHaveLength(26);
});

it('restarts the numbers on a new day in the branch timezone', function () {
    $s = scenario(['timezone' => 'Asia/Jakarta']);

    // 23:30 UTC is 06:30 the next morning in Jakarta.
    Carbon::setTestNow('2026-03-10 23:30:00');
    $late = issue($s->branch, $s->a);
    expect($late->queue_date->toDateString())->toBe('2026-03-11');

    Carbon::setTestNow('2026-03-11 02:00:00');
    expect(issue($s->branch, $s->a)->code)->toBe('A-002');

    Carbon::setTestNow('2026-03-11 18:00:00');
    expect(issue($s->branch, $s->a)->code)->toBe('A-001');
});

it('refuses a service from another branch', function () {
    $s = scenario();
    $other = scenario();

    issue($s->branch, $other->a);
})->throws(QueueException::class);

it('refuses an inactive service', function () {
    $s = scenario();
    $s->a->update(['is_active' => false]);

    issue($s->branch, $s->a);
})->throws(QueueException::class);

it('creates a priority ticket on request', function () {
    $s = scenario();

    expect(issue($s->branch, $s->a, priority: true)->is_priority)->toBeTrue()
        ->and(Ticket::count())->toBe(1);
});
