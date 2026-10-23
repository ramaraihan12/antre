<?php

namespace App\Payments;

use RuntimeException;

/**
 * Decides which payment driver is active and which key signs its notifications.
 *
 * The fake driver exists so the demo can be finished without Midtrans keys. It is
 * refused outright in production.
 */
class Payments
{
    public const MIDTRANS = 'midtrans';

    public const FAKE = 'fake';

    public function driver(): string
    {
        $driver = config('antre.payments.driver')
            ?: (config('antre.payments.midtrans.server_key') ? self::MIDTRANS : self::FAKE);

        if ($driver === self::FAKE && app()->isProduction()) {
            throw new RuntimeException('The fake payment driver is disabled in production. Set MIDTRANS_SERVER_KEY.');
        }

        if ($driver === self::MIDTRANS && ! config('antre.payments.midtrans.server_key')) {
            throw new RuntimeException('MIDTRANS_SERVER_KEY is not set.');
        }

        return $driver;
    }

    public function isFake(): bool
    {
        return $this->driver() === self::FAKE;
    }

    /** The key used to verify notification signatures for the active driver. */
    public function serverKey(): string
    {
        return $this->isFake()
            ? (string) config('antre.payments.fake_server_key')
            : (string) config('antre.payments.midtrans.server_key');
    }

    public function clientKey(): ?string
    {
        return config('antre.payments.midtrans.client_key');
    }

    public function snapScriptUrl(): string
    {
        $host = config('antre.payments.midtrans.is_production') ? 'app.midtrans.com' : 'app.sandbox.midtrans.com';

        return "https://$host/snap/snap.js";
    }

    public function snapApiUrl(): string
    {
        $host = config('antre.payments.midtrans.is_production') ? 'app.midtrans.com' : 'app.sandbox.midtrans.com';

        return "https://$host/snap/v1/transactions";
    }

    /** sha512(order_id + status_code + gross_amount + server key), the way Midtrans signs a notification. */
    public static function signature(string $orderId, string $statusCode, string $grossAmount, string $serverKey): string
    {
        return hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);
    }
}
