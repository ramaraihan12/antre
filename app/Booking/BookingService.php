<?php

namespace App\Booking;

use App\Enums\BookingStatus;
use App\Exceptions\QueueException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Service;
use App\Models\Ticket;
use App\Queue\QueueBroadcaster;
use App\Queue\TicketIssuer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BookingService
{
    public function __construct(
        private readonly SlotCalculator $slots,
        private readonly TicketIssuer $tickets,
        private readonly QueueBroadcaster $broadcaster,
    ) {}

    /**
     * Holds a slot for a customer until they pay or the hold runs out.
     *
     * @param  string  $localDate  Y-m-d in the branch timezone
     * @param  string  $time  H:i in the branch timezone
     */
    public function create(Branch $branch, Service $service, string $localDate, string $time, string $name, string $phone): Booking
    {
        if ($service->branch_id !== $branch->id || ! $service->isBookable()) {
            throw SlotUnavailable::notBookable();
        }

        return DB::transaction(function () use ($branch, $service, $localDate, $time, $name, $phone): Booking {
            // One booking at a time per service, so two customers cannot take the last place together.
            Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

            $startsAt = $this->slots->claim($branch, $service, Carbon::parse($localDate, $branch->timezone), $time);

            return Booking::create([
                'business_id' => $branch->business_id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'customer_name' => $name,
                'customer_phone' => $phone,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes($service->avg_duration_minutes),
                'status' => BookingStatus::PendingPayment,
                'deposit_amount' => $service->deposit_amount,
                'expires_at' => now()->addMinutes((int) config('antre.booking_hold_minutes')),
            ]);
        });
    }

    /**
     * Releases every unpaid booking whose hold has run out. Returns how many were released.
     *
     * One UPDATE with the status in its WHERE clause: if a payment lands at the same moment,
     * the row is already paid and this statement skips it.
     */
    public function expireDue(): int
    {
        $due = Booking::query()
            ->where('status', BookingStatus::PendingPayment)
            ->where('expires_at', '<=', now()->format('Y-m-d H:i:s'))
            ->pluck('public_id', 'id');

        if ($due->isEmpty()) {
            return 0;
        }

        $expired = Booking::query()
            ->whereIn('id', $due->keys())
            ->where('status', BookingStatus::PendingPayment)
            ->update(['status' => BookingStatus::Expired, 'updated_at' => now()]);

        // Tell any open booking page, so it stops showing a 0:00 countdown.
        foreach ($due as $publicId) {
            $this->broadcaster->bookingChanged($publicId);
        }

        return $expired;
    }

    /**
     * Turns a paid booking into a priority ticket. Safe to repeat: a second check-in
     * returns the ticket that already exists.
     */
    public function checkIn(Booking $booking): Ticket
    {
        return DB::transaction(function () use ($booking): Ticket {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->with(['branch', 'service'])->firstOrFail();

            if ($locked->status === BookingStatus::CheckedIn && $locked->ticket_id !== null) {
                return Ticket::query()->findOrFail($locked->ticket_id);
            }

            if ($locked->status !== BookingStatus::Paid) {
                throw new QueueException('Booking '.$locked->order_id.' berstatus '.$locked->status->label().', belum bisa check-in.');
            }

            if ($locked->localStart()->toDateString() !== $locked->branch->today()) {
                throw new QueueException('Booking '.$locked->order_id.' untuk tanggal '.$locked->localStart()->toDateString().', bukan hari ini.');
            }

            $ticket = $this->tickets->issue($locked->branch, $locked->service, $locked, priority: true);

            $locked->update([
                'status' => BookingStatus::CheckedIn,
                'checked_in_at' => now(),
                'ticket_id' => $ticket->id,
            ]);

            return $ticket;
        });
    }
}
