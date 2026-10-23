<?php

use App\Booking\BookingService;
use App\Booking\SlotCalculator;
use App\Booking\SlotUnavailable;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // 08:00 in Jakarta, one hour before the branch opens.
    Carbon::setTestNow('2026-06-15 01:00:00');
    $this->s = scenario();
    $this->slots = app(SlotCalculator::class);
    $this->service = app(BookingService::class);
});

afterEach(fn () => Carbon::setTestNow());

function book(object $s, string $time = '10:00', string $date = '2026-06-15'): Booking
{
    return app(BookingService::class)->create($s->branch, $s->a, $date, $time, 'Dewi Lestari', '081234567890');
}

it('builds slots from opening hours and the service duration', function () {
    $slots = $this->slots->forDay($this->s->branch, $this->s->a, Carbon::parse('2026-06-15'));

    expect($slots)->toHaveCount(32)
        ->and($slots[0]['time'])->toBe('09:00')
        ->and($slots[31]['time'])->toBe('16:45')
        ->and($slots[0]['capacity'])->toBe(2)
        ->and($slots[0]['starts_at'])->toBe('2026-06-15T02:00:00+00:00');
});

it('uses a longer step for a longer service and keeps the last slot inside opening hours', function () {
    $this->s->a->update(['avg_duration_minutes' => 45]);

    $slots = $this->slots->forDay($this->s->branch, $this->s->a->fresh(), Carbon::parse('2026-06-15'));

    // 09:00 to 17:00 is 480 minutes, ten slots of 45 minutes with 30 left over.
    expect($slots)->toHaveCount(10)->and($slots[9]['time'])->toBe('15:45');
});

it('returns no slots on a closed day or outside the booking window', function () {
    $this->s->branch->update(['opening_hours' => array_replace(
        array_fill_keys(range(0, 6), ['09:00', '17:00']),
        [0 => null],
    )]);

    $sunday = Carbon::parse('2026-06-21');
    expect($this->slots->forDay($this->s->branch->fresh(), $this->s->a, $sunday))->toBe([])
        ->and($this->slots->forDay($this->s->branch, $this->s->a, Carbon::parse('2026-06-14')))->toBe([])
        ->and($this->slots->forDay($this->s->branch, $this->s->a, Carbon::parse('2026-07-30')))->toBe([]);
});

it('marks slots in the past as unavailable', function () {
    Carbon::setTestNow('2026-06-15 03:05:00');

    $slots = collect($this->slots->forDay($this->s->branch, $this->s->a, Carbon::parse('2026-06-15')))->keyBy('time');

    expect($slots['10:00']['available'])->toBeFalse()
        ->and($slots['10:15']['available'])->toBeTrue();
});

it('allows as many bookings per slot as there are counters for the service', function () {
    book($this->s);
    book($this->s);

    expect(fn () => book($this->s))->toThrow(SlotUnavailable::class, 'penuh');

    $slot = collect($this->slots->forDay($this->s->branch, $this->s->a, Carbon::parse('2026-06-15')))->firstWhere('time', '10:00');
    expect($slot)->toMatchArray(['taken' => 2, 'capacity' => 2, 'available' => false]);

    // A neighbouring slot is unaffected.
    expect(book($this->s, '10:15'))->toBeInstanceOf(Booking::class);
});

it('counts counters per service, so a one-counter service holds one booking', function () {
    $this->s->b->update(['deposit_amount' => 10000]);
    app(BookingService::class)->create($this->s->branch, $this->s->b, '2026-06-15', '10:00', 'Agus', '0811');

    // Service B is only served by counter 1.
    app(BookingService::class)->create($this->s->branch, $this->s->b, '2026-06-15', '10:00', 'Budi', '0812');
})->throws(SlotUnavailable::class);

it('frees the slot when a booking is cancelled or its hold runs out', function () {
    $first = book($this->s);
    book($this->s);

    $first->update(['status' => BookingStatus::Cancelled]);
    expect(book($this->s))->toBeInstanceOf(Booking::class);
    expect(fn () => book($this->s))->toThrow(SlotUnavailable::class);

    // The hold of an unpaid booking stops counting as soon as it passes, even before the sweep runs.
    Carbon::setTestNow('2026-06-15 01:16:00');
    expect(book($this->s))->toBeInstanceOf(Booking::class);
});

it('keeps paid bookings in the slot after their hold time', function () {
    book($this->s)->update(['status' => BookingStatus::Paid]);
    book($this->s)->update(['status' => BookingStatus::Paid]);

    Carbon::setTestNow('2026-06-15 02:00:00');

    expect(fn () => book($this->s))->toThrow(SlotUnavailable::class);
});

it('rejects times that are not slot starts, closed hours and the past', function (string $time, string $date) {
    book($this->s, $time, $date);
})->with([
    'between slots' => ['10:07', '2026-06-15'],
    'before opening' => ['08:45', '2026-06-15'],
    'after the last slot' => ['17:00', '2026-06-15'],
    'yesterday' => ['10:00', '2026-06-14'],
])->throws(SlotUnavailable::class);

it('refuses a service without a deposit', function () {
    app(BookingService::class)->create($this->s->branch, $this->s->b, '2026-06-15', '10:00', 'Agus', '0811');
})->throws(SlotUnavailable::class, 'online');

it('stores the booking in UTC with a fifteen minute hold', function () {
    $booking = book($this->s);

    expect($booking->starts_at->toDateTimeString())->toBe('2026-06-15 03:00:00')
        ->and($booking->ends_at->toDateTimeString())->toBe('2026-06-15 03:15:00')
        ->and($booking->expires_at->toDateTimeString())->toBe('2026-06-15 01:15:00')
        ->and($booking->status)->toBe(BookingStatus::PendingPayment)
        ->and($booking->deposit_amount)->toBe(25000)
        ->and($booking->order_id)->toStartWith('ANT-');
});
