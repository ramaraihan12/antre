<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\PaymentNotification;
use App\Models\Ticket;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // 08:30 in Jakarta, before the branch opens at 09:00.
    Carbon::setTestNow('2026-06-15 01:30:00');
    $this->s = scenario();
});

afterEach(fn () => Carbon::setTestNow());

function bookingRequest(object $s, array $overrides = []): array
{
    return array_merge([
        'service_id' => $s->a->id,
        'date' => '2026-06-15',
        'time' => '10:00',
        'name' => 'Wulan Sari',
        'phone' => '0812 3456 7890',
    ], $overrides);
}

it('offers bookable services and the open days', function () {
    $this->get("/book/{$this->s->branch->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('book/create')
            ->has('services', 1)
            ->where('services.0.deposit', 25000)
            ->has('days', 15)
            ->where('days.0.date', '2026-06-15'));
});

it('returns the slots of a day as JSON', function () {
    $this->getJson("/book/{$this->s->branch->slug}/slots?service_id={$this->s->a->id}&date=2026-06-15")
        ->assertOk()
        ->assertJsonCount(32, 'slots')
        ->assertJsonPath('slots.0.time', '09:00')
        ->assertJsonPath('slots.0.available', true);
});

it('creates a pending booking and sends the customer to its page', function () {
    $response = $this->post("/book/{$this->s->branch->slug}", bookingRequest($this->s));

    $booking = Booking::firstOrFail();
    $response->assertRedirect("/b/{$booking->public_id}");
    expect($booking->status)->toBe(BookingStatus::PendingPayment)
        ->and($booking->customer_name)->toBe('Wulan Sari');
});

it('rejects a full slot with a message the form can show', function () {
    $this->post("/book/{$this->s->branch->slug}", bookingRequest($this->s));
    $this->post("/book/{$this->s->branch->slug}", bookingRequest($this->s, ['name' => 'Eko']));

    $this->post("/book/{$this->s->branch->slug}", bookingRequest($this->s, ['name' => 'Tono']))
        ->assertSessionHasErrors(['time' => 'Jam ini sudah penuh. Pilih jam lain.']);

    expect(Booking::count())->toBe(2);
});

it('validates the form', function (array $override, string $field) {
    $this->post("/book/{$this->s->branch->slug}", bookingRequest($this->s, $override))->assertSessionHasErrors($field);
})->with([
    'no name' => [['name' => ''], 'name'],
    'bad phone' => [['phone' => 'abc'], 'phone'],
    'bad time' => [['time' => '10.00'], 'time'],
    'bad date' => [['date' => '15-06-2026'], 'date'],
]);

it('will not book a service of another branch', function () {
    $other = scenario();

    $this->post("/book/{$this->s->branch->slug}", bookingRequest($this->s, ['service_id' => $other->a->id]))
        ->assertSessionHasErrors('service_id');
});

describe('paying with the local fake driver', function () {
    it('completes a booking through the same notification path as the real webhook', function () {
        $this->post("/book/{$this->s->branch->slug}", bookingRequest($this->s));
        $booking = Booking::firstOrFail();

        $this->get("/b/{$booking->public_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('book/show')
                ->where('booking.status', 'pending_payment')
                ->where('payment.driver', 'fake')
                ->where('payment.fake_available', true));

        $this->post("/b/{$booking->public_id}/fake-pay")->assertSessionHas('status', 'Pembayaran simulasi berhasil.');

        expect($booking->fresh()->status)->toBe(BookingStatus::Paid)
            ->and(PaymentNotification::first()->outcome)->toBe('applied');
    });

    it('is not available once a real Midtrans key is configured', function () {
        config(['antre.payments.driver' => null, 'antre.payments.midtrans.server_key' => 'SB-Mid-server-test']);
        $booking = Booking::factory()->create(['service_id' => $this->s->a->id]);

        $this->post("/b/{$booking->public_id}/fake-pay")->assertNotFound();
        expect($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
    });

    it('is not available in production', function () {
        app()->detectEnvironment(fn () => 'production');
        $booking = Booking::factory()->create(['service_id' => $this->s->a->id]);

        // Outside the testing environment the CSRF check is on, so switch it off to reach the route.
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/b/{$booking->public_id}/fake-pay")
            ->assertNotFound();
        expect($booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
    });
});

describe('paying with Midtrans Snap', function () {
    beforeEach(function () {
        config(['antre.payments.driver' => 'midtrans', 'antre.payments.midtrans.server_key' => 'SB-Mid-server-test', 'antre.payments.midtrans.client_key' => 'SB-Mid-client-test']);
        $this->booking = Booking::factory()->create([
            'service_id' => $this->s->a->id,
            'expires_at' => now()->addMinutes(15),
            'customer_name' => 'Wulan Sari',
        ]);
    });

    it('asks Snap for a token with the deposit as the amount and returns it to the page', function () {
        Http::fake(['app.sandbox.midtrans.com/*' => Http::response(['token' => 'snap-token-123', 'redirect_url' => 'https://example.test'], 201)]);

        $this->post("/b/{$this->booking->public_id}/pay")->assertSessionHas('snap_token', 'snap-token-123');

        Http::assertSent(fn ($request) => $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions'
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('SB-Mid-server-test:')
            && $request['transaction_details']['order_id'] === $this->booking->order_id
            && $request['transaction_details']['gross_amount'] === 25000
            && $request['expiry']['unit'] === 'minutes');
        expect($this->booking->fresh()->snap_token)->toBe('snap-token-123');
    });

    it('reuses the token instead of creating a second Snap transaction', function () {
        Http::fake(['*' => Http::response(['token' => 'snap-token-123'], 201)]);

        $this->post("/b/{$this->booking->public_id}/pay");
        $this->post("/b/{$this->booking->public_id}/pay");

        Http::assertSentCount(1);
    });

    it('tells the customer to retry when Midtrans fails', function () {
        Http::fake(['*' => Http::response(['error_messages' => ['nope']], 500)]);

        $this->post("/b/{$this->booking->public_id}/pay")->assertSessionHasErrors('payment');
        expect($this->booking->fresh()->snap_token)->toBeNull();
    });

    it('does not start a payment for a booking that already expired', function () {
        Http::fake();
        $this->booking->update(['status' => BookingStatus::Expired]);

        $this->post("/b/{$this->booking->public_id}/pay")->assertSessionHasErrors('payment');
        Http::assertNothingSent();
    });

    it('gives the page the Snap script and client key, and no fake button', function () {
        $this->get("/b/{$this->booking->public_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment.driver', 'midtrans')
                ->where('payment.client_key', 'SB-Mid-client-test')
                ->where('payment.snap_url', 'https://app.sandbox.midtrans.com/snap/snap.js')
                ->where('payment.fake_available', false));
    });
});

describe('check-in at the counter', function () {
    it('turns a paid booking into a priority ticket for the staff of that business', function () {
        Carbon::setTestNow('2026-06-15 02:30:00');
        $this->post("/book/{$this->s->branch->slug}", bookingRequest($this->s));
        $booking = Booking::firstOrFail();
        $this->post("/b/{$booking->public_id}/fake-pay");
        issue($this->s->branch, $this->s->a);

        $this->actingAs(staffOf($this->s->business))
            ->post('/console/check-in', ['reference' => strtolower($booking->order_id)])
            ->assertSessionHas('status');

        $ticket = Ticket::where('booking_id', $booking->id)->firstOrFail();
        expect($ticket->is_priority)->toBeTrue()
            ->and($booking->fresh()->status)->toBe(BookingStatus::CheckedIn);

        $this->get("/b/{$booking->public_id}")
            ->assertInertia(fn (Assert $page) => $page->where('booking.status', 'checked_in')->where('booking.ticket_public_id', $ticket->public_id));
    });

    it('explains when the booking is not paid yet', function () {
        Carbon::setTestNow('2026-06-15 02:30:00');
        $booking = Booking::factory()->create([
            'service_id' => $this->s->a->id,
            'starts_at' => '2026-06-15 04:00:00',
            'ends_at' => '2026-06-15 04:15:00',
        ]);

        $this->actingAs(staffOf($this->s->business))
            ->post('/console/check-in', ['reference' => $booking->order_id])
            ->assertSessionHasErrors('queue');
    });

    it('reports an unknown reference', function () {
        $this->actingAs(staffOf($this->s->business))
            ->post('/console/check-in', ['reference' => 'ANT-NOPE'])
            ->assertSessionHasErrors(['reference' => 'Booking tidak ditemukan.']);
    });
});
