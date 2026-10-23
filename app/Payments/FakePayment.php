<?php

namespace App\Payments;

use App\Models\Booking;
use RuntimeException;

/**
 * Local-only stand-in for the customer paying in Midtrans.
 *
 * It builds a notification the way Midtrans would, signs it with the fake server key and
 * hands it to the same processor the real webhook route uses. Refused in production.
 */
class FakePayment
{
    public function __construct(
        private readonly Payments $payments,
        private readonly NotificationProcessor $processor,
    ) {}

    public function available(): bool
    {
        return ! app()->isProduction() && $this->payments->driver() === Payments::FAKE;
    }

    /** @return string The processor outcome. */
    public function send(Booking $booking, string $transactionStatus = 'settlement'): string
    {
        if (! $this->available()) {
            throw new RuntimeException('The fake payment driver is not active.');
        }

        return $this->processor->handle($this->payload($booking, $transactionStatus));
    }

    /**
     * What Midtrans would POST to /webhooks/midtrans, signed with the fake key.
     *
     * @return array<string, string>
     */
    public function payload(Booking $booking, string $transactionStatus = 'settlement'): array
    {
        $statusCode = $transactionStatus === 'settlement' ? '200' : '201';
        $gross = number_format($booking->deposit_amount, 2, '.', '');

        return [
            'order_id' => $booking->order_id,
            'transaction_id' => 'fake-'.$booking->public_id,
            'transaction_status' => $transactionStatus,
            'status_code' => $statusCode,
            'gross_amount' => $gross,
            'payment_type' => 'fake',
            'signature_key' => Payments::signature($booking->order_id, $statusCode, $gross, $this->payments->serverKey()),
        ];
    }
}
