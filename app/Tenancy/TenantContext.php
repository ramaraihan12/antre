<?php

namespace App\Tenancy;

use Closure;

/**
 * Holds the business the current request works for. The staff area sets it in a
 * middleware, public pages leave it empty and look rows up through a branch instead.
 */
final class TenantContext
{
    private ?int $businessId = null;

    public function id(): ?int
    {
        return $this->businessId;
    }

    public function set(?int $businessId): void
    {
        $this->businessId = $businessId;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(int $businessId, Closure $callback): mixed
    {
        $previous = $this->businessId;
        $this->businessId = $businessId;

        try {
            return $callback();
        } finally {
            $this->businessId = $previous;
        }
    }
}
