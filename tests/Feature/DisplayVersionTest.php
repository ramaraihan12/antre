<?php

use App\Events\DisplayUpdated;
use App\Exceptions\QueueException;
use App\Models\Branch;
use App\Queue\DisplaySnapshot;
use App\Queue\QueueDesk;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => $this->desk = app(QueueDesk::class));

/** The version the branch row holds right now. */
function branchVersion(Branch $branch): int
{
    return (int) Branch::query()->whereKey($branch->id)->value('display_version');
}

/** @return list<int> */
function pushedVersions(): array
{
    return Event::dispatched(DisplayUpdated::class)
        ->map(fn (array $args) => $args[0]->snapshot['version'])
        ->values()
        ->all();
}

it('gives every pushed snapshot its own version, counting up by one per change', function () {
    Event::fake([DisplayUpdated::class]);
    $s = scenario();
    foreach (range(1, 4) as $_) {
        issue($s->branch, $s->a);
    }

    // Four changes in a row, far inside the same millisecond. A clock cannot tell them apart.
    $first = $this->desk->callNext($s->c1);
    $second = $this->desk->callNext($s->c2);
    $this->desk->done($first);
    $this->desk->skip($second);

    expect(pushedVersions())->toBe([1, 2, 3, 4])
        ->and(branchVersion($s->branch))->toBe(4);
});

it('takes the version from the branch row, not from the clock', function () {
    $s = scenario();
    Branch::query()->whereKey($s->branch->id)->update(['display_version' => 41]);
    issue($s->branch, $s->a);

    $this->desk->callNext($s->c1);

    expect(app(DisplaySnapshot::class)->for($s->branch->fresh())['version'])->toBe(42);
});

it('counts a recall as a change too, so the announcement and the snapshot stay in step', function () {
    Event::fake([DisplayUpdated::class]);
    $s = scenario();
    issue($s->branch, $s->a);

    $called = $this->desk->callNext($s->c1);
    $this->desk->recall($called);

    expect(pushedVersions())->toBe([1, 2]);
});

it('does not use up a version when nothing changed', function () {
    Event::fake([DisplayUpdated::class]);
    $s = scenario();

    expect($this->desk->callNext($s->c1))->toBeNull();

    issue($s->branch, $s->a);
    $this->desk->callNext($s->c1);
    expect(fn () => $this->desk->callNext($s->c1))->toThrow(QueueException::class);

    expect(branchVersion($s->branch))->toBe(1)
        ->and(pushedVersions())->toBe([1]);
});

it('keeps the versions of different branches apart', function () {
    $s = scenario();
    $other = scenario();
    issue($s->branch, $s->a);
    issue($other->branch, $other->a);

    $this->desk->callNext($s->c1);
    $this->desk->callNext($other->c1);

    expect(branchVersion($s->branch))->toBe(1)
        ->and(branchVersion($other->branch))->toBe(1);
});

it('carries the branch version together with the matching content', function () {
    $s = scenario();
    issue($s->branch, $s->a);
    $this->desk->callNext($s->c1);

    $snapshot = app(DisplaySnapshot::class)->for($s->branch->fresh());

    expect($snapshot['version'])->toBe(1)
        ->and($snapshot['counters'][0]['code'])->toBe('A-001');
});
