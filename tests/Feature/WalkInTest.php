<?php

use App\Enums\TicketStatus;
use App\Events\DisplayUpdated;
use App\Events\QueueChanged;
use App\Events\StaffQueueUpdated;
use App\Models\Ticket;
use App\Queue\QueueDesk;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->s = scenario();
});

it('lists the active services of a branch with how many are waiting', function () {
    issue($this->s->branch, $this->s->a);
    issue($this->s->branch, $this->s->a);
    $this->s->b->update(['is_active' => false]);

    $this->get("/q/{$this->s->branch->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('queue/join')
            ->has('services', 1)
            ->where('services.0.code', 'A')
            ->where('services.0.waiting', 2));
});

it('hands out a ticket and redirects to its page', function () {
    $response = $this->post("/q/{$this->s->branch->slug}/tickets", ['service_id' => $this->s->a->id]);

    $ticket = Ticket::firstOrFail();
    $response->assertRedirect("/t/{$ticket->public_id}");
    expect($ticket->code)->toBe('A-001')->and($ticket->status)->toBe(TicketStatus::Waiting);
});

it('shows position and estimated wait on the ticket page', function () {
    issue($this->s->branch, $this->s->a);
    issue($this->s->branch, $this->s->a);
    $mine = issue($this->s->branch, $this->s->a);

    $this->get("/t/{$mine->public_id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('queue/ticket')
            ->where('ticket.code', 'A-003')
            ->where('ticket.status', 'waiting')
            ->where('estimate.position', 3)
            ->where('estimate.ahead', 2)
            // 2 ahead at the 15 minute default, two counters on A: ceil(30 / 2)
            ->where('estimate.minutes', 15)
            ->where('branch.id', $this->s->branch->id));
});

it('moves a customer up as tickets ahead are called and tells them when it is their turn', function () {
    $first = issue($this->s->branch, $this->s->a);
    $mine = issue($this->s->branch, $this->s->a);
    $desk = app(QueueDesk::class);

    $desk->done($desk->callNext($this->s->c1));
    $this->get("/t/{$mine->public_id}")->assertInertia(fn (Assert $page) => $page->where('estimate.position', 1)->where('estimate.ahead', 0));

    $desk->callNext($this->s->c2);
    $this->get("/t/{$mine->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('ticket.status', 'called')
        ->where('ticket.counter', 'Loket 2'));

    expect($first->fresh()->status)->toBe(TicketStatus::Done);
});

it('refuses a service id from another branch', function () {
    $other = scenario();

    $this->post("/q/{$this->s->branch->slug}/tickets", ['service_id' => $other->a->id])
        ->assertSessionHasErrors('service_id');

    expect(Ticket::count())->toBe(0);
});

it('lets a customer cancel while waiting but not after being called', function () {
    $ticket = issue($this->s->branch, $this->s->a);

    $this->post("/t/{$ticket->public_id}/cancel")->assertRedirect();
    expect($ticket->fresh()->status)->toBe(TicketStatus::Cancelled);

    $called = issue($this->s->branch, $this->s->a);
    app(QueueDesk::class)->callNext($this->s->c1);
    $this->post("/t/{$called->public_id}/cancel")->assertSessionHasErrors('ticket');
    expect($called->fresh()->status)->toBe(TicketStatus::Called);
});

it('returns 404 for an unknown ticket or branch', function () {
    $this->get('/t/does-not-exist')->assertNotFound();
    $this->get('/q/no-such-branch')->assertNotFound();
    $this->get('/display/no-such-branch')->assertNotFound();
});

describe('what gets broadcast', function () {
    it('announces a call on the display channel with the Indonesian sentence', function () {
        Event::fake([DisplayUpdated::class, QueueChanged::class, StaffQueueUpdated::class]);
        foreach (range(1, 12) as $i) {
            issue($this->s->branch, $this->s->a);
        }
        $desk = app(QueueDesk::class);
        foreach (range(1, 11) as $i) {
            $desk->done($desk->callNext($this->s->c2));
        }
        Event::fake([DisplayUpdated::class, QueueChanged::class, StaffQueueUpdated::class]);

        $desk->callNext($this->s->c2);

        Event::assertDispatched(DisplayUpdated::class, function (DisplayUpdated $event) {
            return $event->branchId === $this->s->branch->id
                && $event->announcement['code'] === 'A-012'
                && $event->announcement['text'] === 'Nomor antrean A dua belas, silakan ke loket dua'
                && collect($event->snapshot['counters'])->firstWhere('number', 2)['code'] === 'A-012'
                && $event->snapshot['recent'][0]['code'] === 'A-012';
        });
        Event::assertDispatched(QueueChanged::class);
        Event::assertDispatched(StaffQueueUpdated::class);
    });

    it('clears the counter on the display when the ticket is done, without announcing', function () {
        issue($this->s->branch, $this->s->a);
        $ticket = app(QueueDesk::class)->callNext($this->s->c1);
        Event::fake([DisplayUpdated::class]);

        app(QueueDesk::class)->done($ticket);

        Event::assertDispatched(DisplayUpdated::class, fn (DisplayUpdated $e) => $e->announcement === null
            && collect($e->snapshot['counters'])->firstWhere('number', 1)['code'] === null);
    });

    it('does not fail a call when the websocket server is down', function () {
        Event::listen(DisplayUpdated::class, fn () => throw new RuntimeException('connection refused'));
        issue($this->s->branch, $this->s->a);

        $ticket = app(QueueDesk::class)->callNext($this->s->c1);

        expect($ticket->status)->toBe(TicketStatus::Called);
    });
});

it('renders the display with one entry per counter and the last five calls', function () {
    $desk = app(QueueDesk::class);
    foreach (range(1, 7) as $i) {
        issue($this->s->branch, $this->s->a);
    }
    foreach (range(1, 6) as $i) {
        $desk->done($desk->callNext($this->s->c1));
    }
    $desk->callNext($this->s->c2);

    $this->get("/display/{$this->s->branch->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('display/show')
            ->has('snapshot.counters', 2)
            ->where('snapshot.counters.0.code', null)
            ->where('snapshot.counters.1.code', 'A-007')
            ->has('snapshot.recent', 5)
            ->where('snapshot.recent.0.code', 'A-007')
            ->where('snapshot.recent.4.code', 'A-003'));
});
