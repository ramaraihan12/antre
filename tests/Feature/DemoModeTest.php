<?php

use App\Broadcasting\AblyTokens;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Ticket;
use App\Models\User;
use App\Payments\FakePayment;
use App\Payments\Payments;
use App\Providers\AppServiceProvider;
use Database\Seeders\DemoDaySeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Inertia\Testing\AssertableInertia;

function demoOn(): void
{
    config(['antre.demo.enabled' => true]);
}

function asProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
}

function heldBooking(object $s, array $overrides = []): Booking
{
    return Booking::factory()->create([
        'business_id' => $s->business->id,
        'branch_id' => $s->branch->id,
        'service_id' => $s->a->id,
        'status' => BookingStatus::PendingPayment,
        'expires_at' => now()->addMinutes(15),
        ...$overrides,
    ]);
}

describe('shared accounts', function () {
    beforeEach(function () {
        $this->user = User::factory()->create(['password' => 'password']);
    });

    it('refuses password and account changes when the flag is on', function () {
        demoOn();

        $this->actingAs($this->user)->put('/settings/password', [
            'current_password' => 'password',
            'password' => 'something-new-1',
            'password_confirmation' => 'something-new-1',
        ])->assertForbidden();

        $this->actingAs($this->user)->patch('/settings/profile', ['name' => 'Taken', 'email' => 'taken@example.test'])->assertForbidden();
        $this->actingAs($this->user)->delete('/settings/profile', ['password' => 'password'])->assertForbidden();

        $fresh = $this->user->fresh();

        expect($fresh)->not->toBeNull()
            ->and($fresh->email)->toBe($this->user->email)
            ->and(Hash::check('password', $fresh->password))->toBeTrue();
    });

    it('refuses the password reset routes when the flag is on', function () {
        demoOn();

        $this->post('/forgot-password', ['email' => $this->user->email])->assertForbidden();
        $this->post('/reset-password', [
            'token' => 'x',
            'email' => $this->user->email,
            'password' => 'something-new-1',
            'password_confirmation' => 'something-new-1',
        ])->assertForbidden();
    });

    it('changes nothing when the flag is off', function () {
        $this->actingAs($this->user)->put('/settings/password', [
            'current_password' => 'password',
            'password' => 'something-new-1',
            'password_confirmation' => 'something-new-1',
        ])->assertRedirect();

        expect(Hash::check('something-new-1', $this->user->fresh()->password))->toBeTrue();
    });

    it('shows the logins only on the demo', function () {
        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page->where('demo', null)->where('canResetPassword', true));

        demoOn();

        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('demo.password', 'password')
            ->where('demo.logins.0.email', 'dimas@senyum.test')
            ->where('canResetPassword', false));
    });
});

describe('fake payments', function () {
    it('stay refused in production without the flag', function () {
        config(['antre.payments.driver' => 'fake']);
        asProduction();

        expect(fn () => app(Payments::class)->driver())->toThrow(RuntimeException::class)
            ->and(app(FakePayment::class)->available())->toBeFalse();
    });

    it('work in production on the demo, and the page says so', function () {
        config(['antre.payments.driver' => 'fake']);
        demoOn();
        asProduction();

        expect(app(FakePayment::class)->available())->toBeTrue();

        // CSRF is only skipped while the environment says "testing".
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $booking = heldBooking(scenario());

        $this->get("/b/{$booking->public_id}")->assertOk();
        $this->post("/b/{$booking->public_id}/fake-pay")->assertRedirect();

        expect($booking->fresh()->status)->toBe(BookingStatus::Paid);
    });
});

describe('expired holds without a scheduler', function () {
    beforeEach(function () {
        $this->s = scenario();
    });

    it('shows a lapsed hold as expired on the first visit to its page', function () {
        $booking = heldBooking($this->s, ['expires_at' => now()->subMinute()]);

        $this->get("/b/{$booking->public_id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('booking.status', 'expired'));

        expect($booking->fresh()->status)->toBe(BookingStatus::Expired);
    });

    it('leaves a hold that is still running alone', function () {
        $booking = heldBooking($this->s);

        $this->get("/b/{$booking->public_id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('booking.status', 'pending_payment'));
    });

    it('releases other lapsed holds too, a limited number per request', function () {
        $lapsed = collect(range(1, 25))->map(fn () => heldBooking($this->s, ['expires_at' => now()->subMinute()]));

        $this->get("/book/{$this->s->branch->slug}")->assertOk();

        expect($lapsed->filter(fn (Booking $b) => $b->fresh()->status === BookingStatus::Expired))->toHaveCount(20);
    });

    it('sweeps before staff check a booking in', function () {
        $booking = heldBooking($this->s, ['expires_at' => now()->subMinute()]);

        $this->actingAs(staffOf($this->s->business))
            ->post('/console/check-in', ['reference' => $booking->order_id])
            ->assertSessionHasErrors('queue');

        expect($booking->fresh()->status)->toBe(BookingStatus::Expired);
    });
});

describe('rate limits', function () {
    beforeEach(function () {
        $this->s = scenario();
        $this->url = "/q/{$this->s->branch->slug}/tickets";
        $this->body = ['service_id' => $this->s->a->id];
    });

    it('stops one visitor after the per-minute limit', function () {
        demoOn();
        config(['antre.demo.writes_per_minute' => 3]);

        foreach (range(1, 3) as $i) {
            $this->post($this->url, $this->body)->assertRedirect();
        }

        $this->post($this->url, $this->body)->assertTooManyRequests();
    });

    it('counts each visitor separately', function () {
        demoOn();
        config(['antre.demo.writes_per_minute' => 1]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->post($this->url, $this->body)->assertRedirect();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->post($this->url, $this->body)->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])->post($this->url, $this->body)->assertRedirect();
    });

    it('stops everyone at the global cap', function () {
        demoOn();
        config(['antre.demo.global_writes_per_hour' => 2]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->post($this->url, $this->body)->assertRedirect();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])->post($this->url, $this->body)->assertRedirect();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.3'])->post($this->url, $this->body)->assertTooManyRequests();
    });

    it('does not let a blocked visitor use up the global cap', function () {
        demoOn();
        config(['antre.demo.writes_per_minute' => 1, 'antre.demo.global_writes_per_hour' => 2]);

        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->post($this->url, $this->body);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])->post($this->url, $this->body)->assertRedirect();
    });

    it('covers every public write route', function () {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
            ->reject(fn ($route) => in_array('auth', $route->gatherMiddleware(), true) && ! str_starts_with($route->uri(), 'console'))
            ->reject(fn ($route) => in_array('demo.locked', $route->gatherMiddleware(), true))
            ->reject(fn ($route) => str_starts_with($route->uri(), 'broadcasting/'))
            // Laravel's own upload route, which only takes a signed URL.
            ->reject(fn ($route) => str_starts_with($route->uri(), 'storage/'));

        expect($routes)->not->toBeEmpty();

        foreach ($routes as $route) {
            expect($route->gatherMiddleware())->toContain('throttle:public-write');
        }
    });

    it('adds nothing when the flag is off', function () {
        config(['antre.demo.writes_per_minute' => 1]);

        $this->post($this->url, $this->body)->assertRedirect();
        $this->post($this->url, $this->body)->assertRedirect();
    });
});

describe('Ably tokens', function () {
    beforeEach(function () {
        config(['broadcasting.default' => 'ably']);
        $this->issued = null;

        $this->mock(AblyTokens::class)->shouldReceive('issue')->andReturnUsing(function (array $capability) {
            $this->issued = $capability;

            return ['token' => 'token', 'issued' => 1, 'expires' => 2, 'capability' => json_encode($capability)];
        });
    });

    it('lets a visitor listen on public channels and nothing else', function () {
        $this->getJson('/broadcasting/ably-token')->assertOk()->assertJsonPath('token', 'token');

        expect($this->issued)->toBe(['public:*' => ['subscribe']]);
    });

    it('adds the branches a staff member works at', function () {
        $s = scenario();
        $other = Branch::factory()->for($s->business)->create();
        $staff = User::factory()->create(['business_id' => $s->business->id, 'branch_id' => $s->branch->id, 'role' => Role::Staff]);

        $this->actingAs($staff)->getJson('/broadcasting/ably-token')->assertOk();

        expect($this->issued)->toHaveKey("private:staff.branch.{$s->branch->id}")
            ->and($this->issued)->not->toHaveKey("private:staff.branch.{$other->id}");
    });

    it('gives an owner every branch of the business and none of another', function () {
        $s = scenario();
        $second = Branch::factory()->for($s->business)->create();
        $stranger = scenario();

        $this->actingAs(staffOf($s->business, Role::Owner))->getJson('/broadcasting/ably-token')->assertOk();

        expect($this->issued)->toHaveKeys(["private:staff.branch.{$s->branch->id}", "private:staff.branch.{$second->id}"])
            ->and($this->issued)->not->toHaveKey("private:staff.branch.{$stranger->branch->id}");
    });

    it('never grants publishing', function () {
        $s = scenario();

        $this->actingAs(staffOf($s->business, Role::Owner))->getJson('/broadcasting/ably-token')->assertOk();

        expect(collect($this->issued)->flatten()->unique()->all())->toBe(['subscribe']);
    });

    it('is not there when Ably is not the broadcaster', function () {
        config(['broadcasting.default' => 'reverb']);

        $this->getJson('/broadcasting/ably-token')->assertNotFound();
    });
});

describe('seed data', function () {
    it('keeps normal opening hours by default', function () {
        $this->seed(DemoSeeder::class);

        $branch = Branch::query()->where('slug', 'senyum-salatiga-kota')->firstOrFail();

        expect($branch->hoursOn(Carbon::parse('2026-11-08')))->toBeNull()
            ->and($branch->hoursOn(Carbon::parse('2026-11-09')))->toBe(['08:00', '20:00']);
    });

    it('stays open every day and hour on the demo, so a booking for today is always possible', function () {
        demoOn();
        $this->seed(DemoSeeder::class);

        foreach (Branch::query()->get() as $branch) {
            foreach (range(0, 6) as $offset) {
                expect($branch->hoursOn(Carbon::parse('2026-11-08')->addDays($offset)))->toBe(['00:00', '23:59']);
            }
        }
    });

    it('leaves a short queue on the demo, so a visitor reaches a counter after a few calls', function () {
        demoOn();
        putenv('DEMO_UNTIL=13:30');

        try {
            $this->seed([DemoSeeder::class, DemoDaySeeder::class]);
        } finally {
            putenv('DEMO_UNTIL');
        }

        foreach (Branch::query()->get() as $branch) {
            $tickets = Ticket::query()->where('branch_id', $branch->id)->get();

            expect($tickets->where('status', TicketStatus::Done)->count())->toBeGreaterThan(5)
                ->and($tickets->where('status', TicketStatus::Waiting)->count())->toBeLessThan(7);
        }
    });

    it('seeds the logins the landing page prints', function () {
        demoOn();
        $this->seed(DemoSeeder::class);

        foreach (config('antre.demo.logins') as $login) {
            $user = User::query()->where('email', $login['email'])->firstOrFail();

            expect(Hash::check(config('antre.demo.password'), $user->password))->toBeTrue();
        }
    });
});

describe('sessions on the demo', function () {
    it('are stored without the address or user agent of the visitor', function () {
        config(['session.driver' => 'database']);
        demoOn();
        app()->getProvider(AppServiceProvider::class)->boot();
        Session::forgetDrivers();

        $handler = Session::driver('database')->getHandler();

        expect($handler)->toBeInstanceOf(DatabaseSessionHandler::class);

        $handler->write('demo-session', 'data');

        $row = DB::table('sessions')->where('id', 'demo-session')->first();

        expect($row->ip_address)->toBeNull()->and($row->user_agent)->toBeNull();
    });
});
