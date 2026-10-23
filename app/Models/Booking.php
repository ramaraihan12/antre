<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Tenancy\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Booking extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Booking $booking): void {
            $booking->public_id ??= strtolower((string) Str::ulid());
            $booking->order_id ??= 'ANT-'.strtoupper(Str::random(10));
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'checked_in_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** The start time in the branch's timezone. */
    public function localStart(): Carbon
    {
        return $this->starts_at->copy()->setTimezone($this->branch->timezone);
    }
}
