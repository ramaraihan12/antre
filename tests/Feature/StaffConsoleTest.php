<?php

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Queue\QueueDesk;
use App\Stats\BranchStats;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->s = scenario();
    $this->staff = staffOf($this->s->business);
    $this->actingAs($this->staff);
});

afterEach(fn () => Carbon::setTestNow());

it('redirects guests to the login page', function () {
    auth()->logout();

    $this->get('/console')->assertRedirect('/login');
    $this->post("/console/counters/{$this->s->c1->id}/call-next")->assertRedirect('/login');
});

it('lists the counters of the branch', function () {
    $this->get('/console')
        ->assertInertia(fn (Assert $page) => $page
            ->component('console/index')
            ->has('branches', 1)
            ->has('branches.0.counters', 2)
            ->where('branches.0.counters.0.label', 'Loket 1'));
});

it('calls the next ticket from the console and shows it as current', function () {
    issue($this->s->branch, $this->s->a);

    $this->post("/console/counters/{$this->s->c1->id}/call-next")->assertRedirect()->assertSessionHas('status', 'Memanggil A-001');

    $this->get("/console/counters/{$this->s->c1->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('console/show')
            ->where('current.code', 'A-001')
            ->where('waiting_count', 0));
});

it('says so when nobody is waiting', function () {
    $this->post("/console/counters/{$this->s->c1->id}/call-next")->assertSessionHas('status', 'Tidak ada antrean yang menunggu.');
});

it('refuses a second call while a ticket is open and explains why', function () {
    issue($this->s->branch, $this->s->a);
    issue($this->s->branch, $this->s->a);
    $this->post("/console/counters/{$this->s->c1->id}/call-next");

    $this->post("/console/counters/{$this->s->c1->id}/call-next")->assertSessionHasErrors(['queue' => 'Selesaikan dulu nomor A-001 sebelum memanggil berikutnya.']);
});

it('recalls, skips and finishes the current ticket', function () {
    issue($this->s->branch, $this->s->a);
    $ticket = app(QueueDesk::class)->callNext($this->s->c1);

    $this->post("/console/tickets/{$ticket->id}/recall")->assertSessionHas('status', 'Memanggil ulang A-001');
    expect($ticket->fresh()->call_count)->toBe(2);

    $this->post("/console/tickets/{$ticket->id}/skip")->assertSessionHas('status');
    expect($ticket->fresh()->status)->toBe(TicketStatus::Skipped);

    $this->post("/console/tickets/{$ticket->id}/done")->assertSessionHasErrors('queue');
});

it('shows only waiting tickets for the services of this counter, priority first', function () {
    issue($this->s->branch, $this->s->b);
    issue($this->s->branch, $this->s->a);
    issue($this->s->branch, $this->s->a, priority: true);

    $this->get("/console/counters/{$this->s->c2->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('waiting', 2)
            ->where('waiting.0.code', 'A-002')
            ->where('waiting.0.is_priority', true)
            ->where('waiting.1.code', 'A-001'));
});

it('does not let a staff member open the dashboard', function () {
    $this->get('/dashboard')->assertRedirect('/console');
});

describe('owner dashboard', function () {
    it('reports served count, waits, service times and no-show rate for today', function () {
        // 05:00 UTC is noon in Jakarta.
        Carbon::setTestNow('2026-06-15 05:00:00');
        $owner = staffOf($this->s->business, Role::Owner);

        $make = function (string $at, string $status, ?int $waitMin, ?int $serviceMin) {
            $created = Carbon::parse($at);
            Ticket::factory()->create([
                'service_id' => $this->s->a->id,
                'queue_date' => '2026-06-15',
                'status' => $status,
                'created_at' => $created,
                'called_at' => $waitMin === null ? null : $created->copy()->addMinutes($waitMin),
                'done_at' => $serviceMin === null ? null : $created->copy()->addMinutes($waitMin + $serviceMin),
            ]);
        };

        // Local hours: 09:xx, 09:xx, 10:xx, 12:xx. UTC is seven hours behind.
        $make('2026-06-15 02:10:00', 'done', 10, 20);
        $make('2026-06-15 02:40:00', 'done', 20, 40);
        $make('2026-06-15 03:05:00', 'skipped', 5, null);
        $make('2026-06-15 05:00:00', 'waiting', null, null);
        // Yesterday does not count.
        Ticket::factory()->create(['service_id' => $this->s->a->id, 'queue_date' => '2026-06-14', 'status' => 'done']);

        $stats = app(BranchStats::class)->today($this->s->branch);

        expect($stats)
            ->issued->toBe(4)
            ->served->toBe(2)
            ->waiting->toBe(1)
            ->no_shows->toBe(1)
            ->avg_wait_minutes->toBe(11.7)
            ->avg_service_minutes->toBe(30.0)
            ->no_show_rate->toBe(0.333);

        $perHour = collect($stats['per_hour'])->pluck('tickets', 'hour');
        expect($perHour[9])->toBe(2)->and($perHour[10])->toBe(1)->and($perHour[12])->toBe(1)->and($perHour[11])->toBe(0)
            ->and($perHour->keys()->first())->toBe(9)
            ->and($perHour->keys()->last())->toBe(16);

        $this->actingAs($owner)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('stats.served', 2)
                ->where('stats.no_show_rate', 0.333)
                ->where('branch.id', $this->s->branch->id));
    });

    it('has no averages on an empty day', function () {
        $stats = app(BranchStats::class)->today($this->s->branch);

        expect($stats)->issued->toBe(0)->avg_wait_minutes->toBeNull()->avg_service_minutes->toBeNull()->no_show_rate->toBeNull();
    });

    it('only shows branches of the owner business', function () {
        $other = scenario();
        $owner = staffOf($this->s->business, Role::Owner);

        $this->actingAs($owner)->get("/dashboard?branch={$other->branch->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('branches', 1)
                ->where('branch.id', $this->s->branch->id));
    });
});
