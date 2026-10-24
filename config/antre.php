<?php

return [

    /*
     * How long an unpaid booking keeps its slot.
     */
    'booking_hold_minutes' => (int) env('BOOKING_HOLD_MINUTES', 15),

    /*
     * How many days ahead a customer can book.
     */
    'booking_max_days_ahead' => 14,

    'payments' => [
        // "midtrans", "fake" or empty. Empty picks midtrans when a server key is set, else fake.
        'driver' => env('PAYMENT_DRIVER') ?: null,

        // Used to sign notifications only by the local fake driver. Derived from APP_KEY, so
        // nobody outside an install can forge a notification for it.
        'fake_server_key' => hash_hmac('sha256', 'antre-fake-payments', (string) env('APP_KEY')),

        'midtrans' => [
            'server_key' => env('MIDTRANS_SERVER_KEY'),
            'client_key' => env('MIDTRANS_CLIENT_KEY'),
            'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
        ],
    ],

];
