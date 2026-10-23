<?php

namespace App\Models;

use App\Tenancy\BelongsToBusiness;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property array<int, array{0: string, 1: string}|null> $opening_hours
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use BelongsToBusiness, HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['opening_hours' => 'array'];
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** @return HasMany<Counter, $this> */
    public function counters(): HasMany
    {
        return $this->hasMany(Counter::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** The current time in the branch's own timezone. */
    public function now(): Carbon
    {
        return Carbon::now($this->timezone);
    }

    /** The branch's local date as Y-m-d. Ticket numbers restart on it. */
    public function today(): string
    {
        return $this->now()->toDateString();
    }

    /**
     * Opening and closing time ("09:00", "17:00") for a local date, or null when closed.
     *
     * @return array{0: string, 1: string}|null
     */
    public function hoursOn(Carbon $localDate): ?array
    {
        $hours = $this->opening_hours[$localDate->dayOfWeek] ?? null;

        return $hours === null ? null : [$hours[0], $hours[1]];
    }
}
