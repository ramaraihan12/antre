<?php

namespace App\Payments;

use App\Models\Booking;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Creates the payment session a customer pays in. */
class SnapGateway
{
    public function __construct(private readonly Payments $payments) {}

    /** Returns the Snap token for the booking. */
    public function createToken(Booking $booking): string
    {
        if ($this->payments->isFake()) {
            return 'fake-'.$booking->order_id;
        }

        $response = Http::withBasicAuth((string) config('antre.payments.midtrans.server_key'), '')
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post($this->payments->snapApiUrl(), [
                'transaction_details' => [
                    'order_id' => $booking->order_id,
                    'gross_amount' => $booking->deposit_amount,
                ],
                'item_details' => [[
                    'id' => 'deposit-'.$booking->service_id,
                    'price' => $booking->deposit_amount,
                    'quantity' => 1,
                    'name' => mb_substr('Deposit '.$booking->service->name, 0, 50),
                ]],
                'customer_details' => [
                    'first_name' => $booking->customer_name,
                    'phone' => $booking->customer_phone,
                ],
                // Midtrans stops accepting payment when our own hold runs out.
                'expiry' => [
                    'unit' => 'minutes',
                    'duration' => max(1, (int) now()->diffInMinutes($booking->expires_at, false)),
                ],
            ]);

        $token = $response->json('token');

        if (! $response->successful() || ! is_string($token)) {
            throw new RuntimeException('Midtrans did not return a Snap token (HTTP '.$response->status().').');
        }

        return $token;
    }
}
