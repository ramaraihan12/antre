<?php

namespace App\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Works out which time slots a service can be booked in.
 *
 * Slots start at opening time and step by the service duration, and the last one must end by
 * closing time. A slot holds as many bookings as there are counters serving the service.
 */
class SlotCalculator
{
    /**
     * @return list<array{time: string, starts_at: string, capacity: int, taken: int, available: bool}>
     */
    public function forDay(Branch $branch, Service $service, Carbon $localDate): array
    {
        $hours = $branch->hoursOn($localDate);
        if ($hours === null || ! $this->dateInRange($branch, $localDate)) {
            return [];
        }

        $capacity = $this->capacity($service);
        $step = max(5, $service->avg_duration_minutes);
        $now = Carbon::now();

        $open = $this->localTime($branch, $localDate, $hours[0]);
        $close = $this->localTime($branch, $localDate, $hours[1]);

        $taken = $this->takenBySlot($service, $open, $close);

        $slots = [];
        for ($start = $open->copy(); $start->copy()->addMinutes($step)->lte($close); $start->addMinutes($step)) {
            $count = $taken[$start->copy()->utc()->format('Y-m-d H:i:s')] ?? 0;

            $slots[] = [
                'time' => $start->format('H:i'),
                'starts_at' => $start->copy()->utc()->toIso8601String(),
                'capacity' => $capacity,
                'taken' => $count,
                'available' => $start->gt($now) && $count < $capacity,
            ];
        }

        return $slots;
    }

    /**
     * Checks one slot, for use inside the booking transaction.
     * Returns the UTC start time when the slot exists and still has room.
     *
     * @param  int|null  $ignoreBookingId  A booking that should not count against capacity.
     */
    public function claim(Branch $branch, Service $service, Carbon $localDate, string $time, ?int $ignoreBookingId = null): Carbon
    {
        $hours = $branch->hoursOn($localDate);
        if ($hours === null || ! $this->dateInRange($branch, $localDate)) {
            throw SlotUnavailable::outsideHours();
        }

        $step = max(5, $service->avg_duration_minutes);
        $open = $this->localTime($branch, $localDate, $hours[0]);
        $close = $this->localTime($branch, $localDate, $hours[1]);

        $match = null;
        for ($start = $open->copy(); $start->copy()->addMinutes($step)->lte($close); $start->addMinutes($step)) {
            if ($start->format('H:i') === $time) {
                $match = $start->copy();
                break;
            }
        }

        if ($match === null || $match->lte(Carbon::now())) {
            throw SlotUnavailable::outsideHours();
        }

        $utc = $match->copy()->utc();
        if ($this->countAt($service, $utc, $ignoreBookingId) >= $this->capacity($service)) {
            throw SlotUnavailable::taken();
        }

        return $utc;
    }

    /** Whether a slot at this UTC time has room, ignoring one booking. Used when a late payment arrives. */
    public function hasRoom(Service $service, Carbon $startsAt, ?int $ignoreBookingId = null): bool
    {
        return $this->countAt($service, $startsAt, $ignoreBookingId) < $this->capacity($service);
    }

    public function capacity(Service $service): int
    {
        return $service->counters()->count();
    }

    private function dateInRange(Branch $branch, Carbon $localDate): bool
    {
        $today = Carbon::parse($branch->today(), $branch->timezone)->startOfDay();
        $day = $localDate->copy()->startOfDay();

        return $day->gte($today) && $day->lte($today->copy()->addDays((int) config('antre.booking_max_days_ahead')));
    }

    private function localTime(Branch $branch, Carbon $localDate, string $hhmm): Carbon
    {
        return Carbon::parse($localDate->format('Y-m-d').' '.$hhmm, $branch->timezone);
    }

    private function countAt(Service $service, Carbon $utcStart, ?int $ignoreBookingId): int
    {
        return $this->holdingQuery($service)
            ->where('starts_at', $utcStart->format('Y-m-d H:i:s'))
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->count();
    }

    /**
     * Number of slot-holding bookings per UTC start time within a window.
     *
     * @return array<string, int>
     */
    private function takenBySlot(Service $service, Carbon $from, Carbon $to): array
    {
        return $this->holdingQuery($service)
            ->where('starts_at', '>=', $from->copy()->utc()->format('Y-m-d H:i:s'))
            ->where('starts_at', '<', $to->copy()->utc()->format('Y-m-d H:i:s'))
            ->get(['starts_at'])
            ->countBy(fn (Booking $b) => $b->starts_at->format('Y-m-d H:i:s'))
            ->all();
    }

    /**
     * Bookings that currently hold a slot: paid ones, and unpaid ones whose hold has not run out.
     *
     * @return Builder<Booking>
     */
    private function holdingQuery(Service $service)
    {
        return Booking::query()
            ->where('service_id', $service->id)
            ->where(function ($q) {
                $q->whereIn('status', [BookingStatus::Paid, BookingStatus::CheckedIn])
                    ->orWhere(fn ($pending) => $pending
                        ->where('status', BookingStatus::PendingPayment)
                        ->where('expires_at', '>', Carbon::now()->format('Y-m-d H:i:s')));
            });
    }
}
