<?php

namespace App\Broadcasting;

use Ably\AblyRest;

/** Asks Ably for the tokens browsers connect with, so the key itself never leaves the server. */
class AblyTokens
{
    /**
     * @param  array<string, list<string>>  $capability  Channel patterns and what the holder may do on them.
     * @return array{token: string, issued: int, expires: int, capability: string}
     */
    public function issue(array $capability): array
    {
        $ably = new AblyRest(config('broadcasting.connections.ably'));

        $details = $ably->auth->requestToken([
            'capability' => json_encode($capability, JSON_THROW_ON_ERROR),
            'ttl' => (int) config('antre.ably.token_ttl_hours') * 3_600_000,
        ]);

        return [
            'token' => $details->token,
            'issued' => $details->issued,
            'expires' => $details->expires,
            'capability' => $details->capability,
        ];
    }
}
