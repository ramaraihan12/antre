<?php

namespace App\Payments;

use App\Booking\SlotCalculator;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\PaymentNotification;
use App\Models\Service;
use App\Queue\QueueBroadcaster;
use Illuminate\Support\Facades\DB;

/**
 * Applies a Midtrans HTTP notification to a booking.
 *
 * Three things make this safe to expose to the internet:
 *  - the signature is checked first, with a constant-time compare;
 *  - every accepted notification is logged under a unique key, so a repeat does nothing;
 *  - the booking moves along its state machine only in allowed directions, so a late
 *    "pending" or "expire" cannot undo a payment.
 */
class NotificationProcessor
{
    public const REQUIRED = ['order_id', 'status_code', 'gross_amount', 'signature_key', 'transaction_status'];

    public function __construct(
        private readonly Payments $payments,
        private readonly SlotCalculator $slots,
        private readonly QueueBroadcaster $broadcaster,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return string The outcome, also stored on the notification log.
     *
     * @throws InvalidNotification
     */
    public function handle(array $payload): string
    {
        foreach (self::REQUIRED as $field) {
            if (! isset($payload[$field]) || ! is_scalar($payload[$field])) {
                throw InvalidNotification::malformed($field);
            }
        }

        $orderId = (string) $payload['order_id'];
        $statusCode = (string) $payload['status_code'];
        $gross = (string) $payload['gross_amount'];
        $status = (string) $payload['transaction_status'];

        $expected = Payments::signature($orderId, $statusCode, $gross, $this->payments->serverKey());
        if (! hash_equals($expected, (string) $payload['signature_key'])) {
            throw InvalidNotification::badSignature();
        }

        [$outcome, $publicId] = DB::transaction(function () use ($payload, $orderId, $statusCode, $gross, $status): array {
            $key = hash('sha256', implode('|', [
                $orderId,
                $status,
                $statusCode,
                $gross,
                (string) ($payload['fraud_status'] ?? ''),
                (string) ($payload['transaction_id'] ?? ''),
            ]));

            // INSERT ... ON CONFLICT DO NOTHING: no error, and no aborted transaction, on a repeat.
            $inserted = PaymentNotification::query()->insertOrIgnore([
                'dedupe_key' => $key,
                'order_id' => $orderId,
                'transaction_status' => $status,
                'status_code' => $statusCode,
                'gross_amount' => $gross,
                'payload' => json_encode($this->withoutSecrets($payload)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return ['duplicate', null];
            }

            $log = PaymentNotification::query()->where('dedupe_key', $key)->firstOrFail();

            $booking = Booking::query()->where('order_id', $orderId)->lockForUpdate()->first();

            $outcome = $booking === null ? 'unknown_order' : $this->apply($booking, $status, $gross, $payload);

            $log->update(['booking_id' => $booking?->id, 'outcome' => $outcome]);

            return [$outcome, $booking?->public_id];
        });

        if ($publicId !== null) {
            $this->broadcaster->bookingChanged($publicId);
        }

        return $outcome;
    }

    /** @param  array<string, mixed>  $payload */
    private function apply(Booking $booking, string $status, string $gross, array $payload): string
    {
        if ($status === 'pending') {
            return 'pending_noted';
        }

        if ($this->isPayment($status, $payload)) {
            if ((int) round((float) $gross) !== $booking->deposit_amount) {
                return 'amount_mismatch';
            }

            return $this->markPaid($booking);
        }

        if (in_array($status, ['deny', 'cancel', 'failure'], true)) {
            return $this->moveFromPending($booking, BookingStatus::Cancelled);
        }

        if ($status === 'expire') {
            return $this->moveFromPending($booking, BookingStatus::Expired);
        }

        return 'ignored_status';
    }

    /**
     * Settlement, or a card capture that the fraud check accepted.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isPayment(string $status, array $payload): bool
    {
        if ($status === 'settlement') {
            return true;
        }

        return $status === 'capture' && ($payload['fraud_status'] ?? 'accept') === 'accept';
    }

    private function markPaid(Booking $booking): string
    {
        switch ($booking->status) {
            case BookingStatus::PendingPayment:
                // The hold may have run out before the once-a-minute sweep got to it. The slot
                // could be taken by someone else by now, so treat it like an expired booking.
                if ($booking->expires_at->lte(now())) {
                    return $this->payLate($booking);
                }

                $booking->update(['status' => BookingStatus::Paid, 'paid_at' => now()]);

                return 'applied';

            case BookingStatus::Paid:
            case BookingStatus::CheckedIn:
                return 'ignored_already_paid';

            case BookingStatus::Expired:
                return $this->payLate($booking);

            case BookingStatus::Cancelled:
                return 'ignored_cancelled';
        }
    }

    /**
     * The customer paid after our hold ran out. Honour it if the slot still has room and is
     * still in the future, otherwise leave it expired for a manual refund.
     */
    private function payLate(Booking $booking): string
    {
        // Same lock as BookingService::create, so a new booking cannot take the last place
        // between the capacity check and the update.
        Service::query()->whereKey($booking->service_id)->lockForUpdate()->firstOrFail();

        $stillFree = $booking->starts_at->isFuture()
            && $this->slots->hasRoom($booking->service, $booking->starts_at, $booking->id);

        if (! $stillFree) {
            if ($booking->status === BookingStatus::PendingPayment) {
                $booking->update(['status' => BookingStatus::Expired]);
            }

            return 'late_payment_no_capacity';
        }

        $booking->update(['status' => BookingStatus::Paid, 'paid_at' => now()]);

        return 'late_payment_applied';
    }

    /** Failure and expiry notifications only matter while the booking is still unpaid. */
    private function moveFromPending(Booking $booking, BookingStatus $to): string
    {
        if ($booking->status !== BookingStatus::PendingPayment) {
            return 'ignored_out_of_order';
        }

        $booking->update(['status' => $to]);

        return 'applied';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutSecrets(array $payload): array
    {
        unset($payload['signature_key']);

        return $payload;
    }
}
