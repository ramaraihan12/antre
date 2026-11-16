<?php

use App\Enums\BookingStatus;
use App\Enums\TicketStatus;
use App\Models\Booking;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\TicketCall;
use App\Models\User;
use App\Queue\QueueDesk;
use Illuminate\Support\Facades\File;

function seedLoadTest(array $options = []): array
{
    $path = sys_get_temp_dir().'/antre-manifest-'.uniqid().'.json';
    test()->artisan('antre:loadtest-seed', ['--manifest' => $path, '--branches' => 2, '--services' => 2, '--counters' => 2, '--tickets' => 12, '--bookings' => 3, ...$options])->assertSuccessful();
    $manifest = json_decode(File::get($path), true);
    File::delete($path);

    return $manifest;
}

it('seeds branches, counters, two staff per counter, waiting tickets and paid bookings', function () {
    $manifest = seedLoadTest();

    expect($manifest['branches'])->toHaveCount(2)
        ->and($manifest['branches'][0]['counters'])->toHaveCount(2)
        ->and($manifest['branches'][0]['counters'][0]['staff'])->toHaveCount(2)
        ->and($manifest['branches'][0]['channel'])->toBe('display.'.$manifest['branches'][0]['id'])
        ->and($manifest['branches'][0]['bookings'])->toHaveCount(3)
        ->and(Ticket::where('status', TicketStatus::Waiting)->count())->toBe(24)
        ->and(User::count())->toBe(8)
        ->and(Booking::where('status', BookingStatus::Paid)->count())->toBe(6);

    $email = $manifest['branches'][0]['counters'][0]['staff'][0];
    $this->post('/login', ['email' => $email, 'password' => $manifest['password']])->assertRedirect();
    $this->assertAuthenticated();
});

it('starts over when it runs again', function () {
    seedLoadTest();
    seedLoadTest();

    expect(Ticket::count())->toBe(24)->and(User::count())->toBe(8);
});

it('refuses to seed in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('antre:loadtest-seed')->assertFailed();

    expect(Ticket::count())->toBe(0);
});

it('passes when every ticket was called once and nothing is left open', function () {
    seedLoadTest();
    $desk = app(QueueDesk::class);

    $counters = Counter::all();
    foreach (range(1, 40) as $i) {
        $ticket = $desk->callNext($counters[$i % $counters->count()]);
        $ticket && $desk->done($ticket);
    }

    $this->artisan('antre:loadtest-check')
        ->expectsOutputToContain('PASS  no ticket was called more than once')
        ->expectsOutputToContain('result: PASS')
        ->assertSuccessful();
});

it('fails while tickets are still waiting unless open tickets are allowed', function () {
    seedLoadTest();

    $this->artisan('antre:loadtest-check')->assertFailed();
    $this->artisan('antre:loadtest-check', ['--allow-open' => true])->assertSuccessful();
});

it('fails when a ticket was called twice or sits on two counters', function () {
    seedLoadTest(['--tickets' => 2]);
    $counter = Counter::orderBy('id')->first();
    $other = Counter::whereKeyNot($counter->id)->first();
    $ticket = app(QueueDesk::class)->callNext($counter);

    TicketCall::create([
        'business_id' => $ticket->business_id,
        'branch_id' => $ticket->branch_id,
        'ticket_id' => $ticket->id,
        'counter_id' => $other->id,
        'kind' => 'call',
    ]);

    $this->artisan('antre:loadtest-check', ['--allow-open' => true])
        ->expectsOutputToContain('FAIL  no ticket was called more than once')
        ->expectsOutputToContain('FAIL  no ticket was called on two different counters')
        ->assertFailed();
});

it('fails when a counter holds two called tickets', function () {
    seedLoadTest(['--tickets' => 4]);
    $counter = Counter::orderBy('id')->first();
    app(QueueDesk::class)->callNext($counter);
    $second = Ticket::where('status', TicketStatus::Waiting)->where('branch_id', $counter->branch_id)->first();
    $second->update(['status' => TicketStatus::Called, 'counter_id' => $counter->id]);

    $this->artisan('antre:loadtest-check', ['--allow-open' => true])
        ->expectsOutputToContain('FAIL  no counter holds two called tickets')
        ->assertFailed();
});

it('reports when there is no load test data', function () {
    $this->artisan('antre:loadtest-check')->assertFailed();
});
