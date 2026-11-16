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

it('gives every pushed snapshot its own, higher version', function () {
    Event::fake([DisplayUpdated::class]);
    $s = scenario();
    foreach (range(1, 4) as $_) {
        issue($s->branch, $s->a);
    }

    // Four changes in quick succession. Whatever the clock does, each needs its own number.
    $first = $this->desk->callNext($s->c1);
    $second = $this->desk->callNext($s->c2);
    $this->desk->done($first);
    $this->desk->skip($second);

    $versions = pushedVersions();
    expect($versions)->toHaveCount(4)
        ->and(array_unique($versions))->toHaveCount(4)
        ->and($versions)->toBe(collect($versions)->sort()->values()->all())
        ->and(branchVersion($s->branch))->toBe(end($versions));
});

it('keeps counting up from the branch row when it is ahead of the clock', function () {
    $s = scenario();
    $ahead = (int) floor(microtime(true) * 1000) + 3_600_000;
    Branch::query()->whereKey($s->branch->id)->update(['display_version' => $ahead]);
    issue($s->branch, $s->a);

    $this->desk->callNext($s->c1);

    expect(app(DisplaySnapshot::class)->for($s->branch->fresh())['version'])->toBe($ahead + 1);
});

it('starts above the clock when the branch counter was reset, as after a rebuilt database', function () {
    Event::fake([DisplayUpdated::class]);
    $s = scenario();
    $before = (int) floor(microtime(true) * 1000);
    expect(branchVersion($s->branch))->toBe(0);
    issue($s->branch, $s->a);

    $this->desk->callNext($s->c1);

    // A TV that showed a version from before the reset must still accept this one.
    expect(pushedVersions()[0])->toBeGreaterThanOrEqual($before);
});

it('counts a recall as a change too, so the announcement and the snapshot stay in step', function () {
    Event::fake([DisplayUpdated::class]);
    $s = scenario();
    issue($s->branch, $s->a);

    $called = $this->desk->callNext($s->c1);
    $this->desk->recall($called);

    $versions = pushedVersions();
    expect($versions)->toHaveCount(2)->and($versions[1])->toBeGreaterThan($versions[0]);
});

it('does not use up a version when nothing changed', function () {
    Event::fake([DisplayUpdated::class]);
    $s = scenario();

    expect($this->desk->callNext($s->c1))->toBeNull();

    issue($s->branch, $s->a);
    $this->desk->callNext($s->c1);
    expect(fn () => $this->desk->callNext($s->c1))->toThrow(QueueException::class);

    expect(pushedVersions())->toHaveCount(1)
        ->and(branchVersion($s->branch))->toBe(pushedVersions()[0]);
});

it('keeps the versions of different branches apart', function () {
    $s = scenario();
    $other = scenario();
    issue($s->branch, $s->a);
    issue($other->branch, $other->a);

    $this->desk->callNext($s->c1);
    $this->desk->callNext($other->c1);

    // Moving one branch leaves the other where it was.
    expect(branchVersion($s->branch))->toBeGreaterThan(0)
        ->and(branchVersion($other->branch))->toBeGreaterThan(0);

    $before = branchVersion($other->branch);
    issue($s->branch, $s->a);
    $this->desk->callNext($s->c2);
    expect(branchVersion($other->branch))->toBe($before);
});

it('carries the branch version together with the matching content', function () {
    $s = scenario();
    issue($s->branch, $s->a);
    $this->desk->callNext($s->c1);

    $snapshot = app(DisplaySnapshot::class)->for($s->branch->fresh());

    expect($snapshot['version'])->toBe(branchVersion($s->branch))
        ->and($snapshot['counters'][0]['code'])->toBe('A-001');
});
