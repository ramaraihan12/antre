<?php

use App\Booking\BookingService;
use App\Enums\BookingStatus;
use App\Enums\TicketStatus;
use App\Exceptions\QueueException;
use App\Models\Booking;
use App\Models\Ticket;
use App\Payments\FakePayment;
use App\Queue\QueueDesk;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // 09:30 in Jakarta.
    Carbon::setTestNow('2026-06-15 02:30:00');
    $this->s = scenario();
    $this->bookings = app(BookingService::class);
});

afterEach(fn () => Carbon::setTestNow());

function pendingBooking(object $s, array $attributes = []): Booking
{
    return Booking::factory()->create(array_merge([
        'service_id' => $s->a->id,
        'starts_at' => '2026-06-15 04:00:00',
        'ends_at' => '2026-06-15 04:15:00',
        'expires_at' => '2026-06-15 02:45:00',
    ], $attributes));
}

describe('expiry', function () {
    it('expires unpaid bookings whose hold has passed and leaves the rest alone', function () {
        $stale = pendingBooking($this->s, ['expires_at' => '2026-06-15 02:29:00']);
        $fresh = pendingBooking($this->s);
        $paid = pendingBooking($this->s, ['status' => BookingStatus::Paid, 'expires_at' => '2026-06-15 02:00:00']);

        expect($this->bookings->expireDue())->toBe(1)
            ->and($stale->fresh()->status)->toBe(BookingStatus::Expired)
            ->and($fresh->fresh()->status)->toBe(BookingStatus::PendingPayment)
            ->and($paid->fresh()->status)->toBe(BookingStatus::Paid);
    });

    it('expires a booking exactly when the hold ends', function () {
        $booking = pendingBooking($this->s, ['expires_at' => '2026-06-15 02:30:00']);

        $this->bookings->expireDue();

        expect($booking->fresh()->status)->toBe(BookingStatus::Expired);
    });

    it('does not undo a payment that landed before the sweep', function () {
        $booking = pendingBooking($this->s, ['expires_at' => '2026-06-15 02:29:00']);
        app(FakePayment::class)->send($booking);

        expect($this->bookings->expireDue())->toBe(0)
            ->and($booking->fresh()->status)->toBe(BookingStatus::Paid);
    });

    it('runs from the artisan command the scheduler calls', function () {
        pendingBooking($this->s, ['expires_at' => '2026-06-15 02:00:00']);

        $this->artisan('antre:expire-bookings')->expectsOutput('Expired 1 booking(s).')->assertSuccessful();
    });

    it('is registered to run every minute', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'antre:expire-bookings'));

        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');
    });
});

describe('check-in', function () {
    it('turns a paid booking into a priority ticket', function () {
        $walkIn = issue($this->s->branch, $this->s->a);
        $booking = pendingBooking($this->s, ['status' => BookingStatus::Paid]);

        $ticket = $this->bookings->checkIn($booking);

        expect($ticket->is_priority)->toBeTrue()
            ->and($ticket->booking_id)->toBe($booking->id)
            ->and($ticket->service_id)->toBe($this->s->a->id)
            ->and($booking->fresh())
            ->status->toBe(BookingStatus::CheckedIn)
            ->ticket_id->toBe($ticket->id)
            ->checked_in_at->not->toBeNull();

        // The booked customer is called before the walk-in who arrived first.
        $called = app(QueueDesk::class)->callNext($this->s->c1);
        expect($called->is($ticket))->toBeTrue()
            ->and($called->status)->toBe(TicketStatus::Called)
            ->and($walkIn->fresh()->status)->toBe(TicketStatus::Waiting);
    });

    it('returns the same ticket when checked in twice', function () {
        $booking = pendingBooking($this->s, ['status' => BookingStatus::Paid]);

        $first = $this->bookings->checkIn($booking);
        $second = $this->bookings->checkIn($booking);

        expect($second->is($first))->toBeTrue()
            ->and(Ticket::count())->toBe(1);
    });

    it('refuses bookings that are not paid', function (BookingStatus $status) {
        $booking = pendingBooking($this->s, ['status' => $status]);

        $this->bookings->checkIn($booking);
    })->with([
        BookingStatus::PendingPayment,
        BookingStatus::Expired,
        BookingStatus::Cancelled,
    ])->throws(QueueException::class);

    it('refuses a booking for another day', function () {
        $booking = pendingBooking($this->s, [
            'status' => BookingStatus::Paid,
            'starts_at' => '2026-06-16 04:00:00',
            'ends_at' => '2026-06-16 04:15:00',
        ]);

        $this->bookings->checkIn($booking);
    })->throws(QueueException::class, 'bukan hari ini');

    it('uses the branch day, not the UTC day, to decide what is today', function () {
        // 20:00 UTC on the 15th is 03:00 on the 16th in Jakarta.
        Carbon::setTestNow('2026-06-15 20:00:00');
        $booking = pendingBooking($this->s, [
            'status' => BookingStatus::Paid,
            'starts_at' => '2026-06-16 03:00:00',
            'ends_at' => '2026-06-16 03:15:00',
        ]);

        expect($this->bookings->checkIn($booking)->queue_date->toDateString())->toBe('2026-06-16');
    });
});
