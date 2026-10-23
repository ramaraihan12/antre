<?php

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;

/*
 * Two businesses, each with a branch, services, counters and a waiting ticket.
 * Everything below is staff of A reaching for something that belongs to B.
 */
beforeEach(function () {
    $this->a = scenario();
    $this->b = scenario();

    $this->ticketA = issue($this->a->branch, $this->a->a);
    $this->ticketB = issue($this->b->branch, $this->b->a);

    $this->staffA = staffOf($this->a->business);
    $this->ownerA = staffOf($this->a->business, Role::Owner);
});

describe('route model binding', function () {
    it('lets staff open their own counter', function () {
        $this->actingAs($this->staffA)->get("/console/counters/{$this->a->c1->id}")->assertOk();
    });

    it('answers 404 for a counter of another business', function () {
        $this->actingAs($this->staffA)->get("/console/counters/{$this->b->c1->id}")->assertNotFound();
    });

    it('does not let staff of A call the next ticket on a counter of B', function () {
        $this->actingAs($this->staffA)
            ->post("/console/counters/{$this->b->c1->id}/call-next")
            ->assertNotFound();

        expect($this->ticketB->fresh()->status)->toBe(TicketStatus::Waiting);
    });

    it('does not let staff of A act on a ticket of B', function (string $action) {
        $this->ticketB->update(['status' => TicketStatus::Called, 'counter_id' => $this->b->c1->id]);

        $this->actingAs($this->staffA)->post("/console/tickets/{$this->ticketB->id}/$action")->assertNotFound();

        expect($this->ticketB->fresh()->status)->toBe(TicketStatus::Called);
    })->with(['recall', 'skip', 'done']);

    it('does not find a booking of B by its reference', function () {
        $booking = Booking::factory()->create([
            'service_id' => $this->b->a->id,
            'status' => BookingStatus::Paid,
            'starts_at' => now()->addHour(),
        ]);

        $this->actingAs($this->staffA)
            ->from('/console')
            ->post('/console/check-in', ['reference' => $booking->order_id])
            ->assertSessionHasErrors('reference');

        expect($booking->fresh()->status)->toBe(BookingStatus::Paid)
            ->and(Ticket::withoutGlobalScopes()->where('booking_id', $booking->id)->exists())->toBeFalse();
    });

    it('does not list counters or branches of B in the console', function () {
        $this->actingAs($this->staffA)->get('/console')
            ->assertOk()
            ->assertSee($this->a->branch->name, false)
            ->assertDontSee($this->b->branch->name, false);
    });
});

describe('global scope', function () {
    it('limits every tenant model to the business in the tenant context', function () {
        app(TenantContext::class)->set($this->a->business->id);

        expect(Ticket::count())->toBe(1)
            ->and(Ticket::first()->is($this->ticketA))->toBeTrue()
            ->and(Counter::count())->toBe(2)
            ->and(Ticket::find($this->ticketB->id))->toBeNull();

        app(TenantContext::class)->set(null);
        expect(Ticket::count())->toBe(2);
    });

    it('stamps new rows with the tenant in context', function () {
        app(TenantContext::class)->run($this->a->business->id, function () {
            $counter = Counter::create(['branch_id' => $this->a->branch->id, 'number' => 9]);

            expect($counter->business_id)->toBe($this->a->business->id);
        });
    });

    it('clears the tenant after the request, so nothing leaks into the next one', function () {
        $this->actingAs($this->staffA)->get('/console')->assertOk();

        expect(app(TenantContext::class)->id())->toBeNull();
    });

    it('refuses staff that belong to no business', function () {
        $stray = User::factory()->create(['business_id' => null]);

        $this->actingAs($stray)->get('/console')->assertForbidden();
    });
});

describe('policies, with the scope out of the picture', function () {
    it('denies operating counters, tickets and bookings of another business', function () {
        $booking = Booking::factory()->create(['service_id' => $this->b->a->id]);

        $gate = Gate::forUser($this->staffA);

        expect($gate->denies('operate', Counter::withoutGlobalScopes()->find($this->b->c1->id)))->toBeTrue()
            ->and($gate->denies('operate', Ticket::withoutGlobalScopes()->find($this->ticketB->id)))->toBeTrue()
            ->and($gate->denies('checkIn', Booking::withoutGlobalScopes()->find($booking->id)))->toBeTrue()
            ->and($gate->denies('operate', $this->b->branch))->toBeTrue()
            ->and($gate->denies('viewDashboard', $this->b->branch))->toBeTrue();
    });

    it('allows operating their own', function () {
        $gate = Gate::forUser($this->staffA);

        expect($gate->allows('operate', $this->a->c1))->toBeTrue()
            ->and($gate->allows('operate', $this->ticketA))->toBeTrue();
    });

    it('keeps a staff member pinned to one branch out of the others of the same business', function () {
        $second = Branch::factory()->for($this->a->business)->create();
        $counter = Counter::factory()->for($second)->create(['number' => 1]);
        $pinned = staffOf($this->a->business);
        $pinned->update(['branch_id' => $this->a->branch->id]);

        $this->actingAs($pinned)->get("/console/counters/{$this->a->c1->id}")->assertOk();
        $this->actingAs($pinned)->get("/console/counters/{$counter->id}")->assertForbidden();
        $this->actingAs($pinned)->post("/console/counters/{$counter->id}/call-next")->assertForbidden();
    });

    it('keeps the dashboard for owners', function () {
        $this->actingAs($this->staffA)->get('/dashboard')->assertRedirect('/console');
        $this->actingAs($this->ownerA)->get('/dashboard')->assertOk();
    });
});

describe('broadcast channels', function () {
    beforeEach(fn () => useReverbBroadcaster());

    function authChannel(string $channel): TestResponse
    {
        return test()->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678']);
    }

    it('signs a private staff channel for staff of that branch', function () {
        $this->actingAs($this->staffA);

        authChannel("private-staff.branch.{$this->a->branch->id}")->assertOk()->assertJsonStructure(['auth']);
    });

    it('refuses the private staff channel of another business', function () {
        $this->actingAs($this->staffA);

        authChannel("private-staff.branch.{$this->b->branch->id}")->assertForbidden();
    });

    it('refuses guests', function () {
        authChannel("private-staff.branch.{$this->a->branch->id}")->assertForbidden();
    });

    it('refuses a staff member pinned to a different branch', function () {
        $second = Branch::factory()->for($this->a->business)->create();
        $pinned = staffOf($this->a->business);
        $pinned->update(['branch_id' => $second->id]);
        $this->actingAs($pinned);

        authChannel("private-staff.branch.{$this->a->branch->id}")->assertForbidden();
        authChannel("private-staff.branch.{$second->id}")->assertOk();
    });

    it('refuses a branch id that does not exist', function () {
        $this->actingAs($this->staffA);

        authChannel('private-staff.branch.999999')->assertForbidden();
    });
});
