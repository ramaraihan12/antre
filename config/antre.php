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

    /*
     * The public demo. Off by default, so a normal install behaves as if none of this exists.
     * On, it allows the fake payment driver in production, locks the shared logins, keeps the
     * branches open all day and caps public writes.
     */
    'demo' => [
        'enabled' => (bool) env('DEMO_MODE', false),

        // The branch the landing page sends visitors to.
        'branch' => 'senyum-salatiga-kota',

        // Seeded by DemoSeeder, shown on the landing page and the login page.
        'password' => 'password',
        'logins' => [
            ['email' => 'dimas@senyum.test', 'role' => 'Counter staff, Senyum Salatiga Kota'],
            ['email' => 'owner@senyum.test', 'role' => 'Owner, sees the dashboard'],
        ],

        // Requests to public write routes. The counters live in the cache store, which is
        // Postgres on the demo, so every container sees the same numbers.
        'writes_per_minute' => 20,
        'writes_per_hour' => 60,
        'global_writes_per_hour' => 300,
    ],

    'ably' => [
        // Long on purpose: a TV left open fetches a new token once per period, and that is the
        // only request it ever makes. 24 hours is the most Ably allows.
        'token_ttl_hours' => 12,
    ],

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
