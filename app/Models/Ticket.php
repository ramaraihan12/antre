<?php

namespace App\Models;

use App\Enums\TicketStatus;
use App\Tenancy\BelongsToBusiness;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property TicketStatus $status
 * @property Carbon $queue_date
 * @property Carbon|null $called_at
 * @property Carbon|null $done_at
 * @property bool $is_priority
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use BelongsToBusiness, HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket): void {
            $ticket->public_id ??= strtolower((string) Str::ulid());
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'is_priority' => 'boolean',
            'queue_date' => 'date:Y-m-d',
            'called_at' => 'datetime',
            'done_at' => 'datetime',
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

    /** @return BelongsTo<Counter, $this> */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(Counter::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return HasMany<TicketCall, $this> */
    public function calls(): HasMany
    {
        return $this->hasMany(TicketCall::class);
    }

    /**
     * Waiting tickets in the order they are called: priority first, then arrival.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeInCallingOrder(Builder $query): Builder
    {
        return $query->orderByDesc('is_priority')->orderBy('id');
    }

    public static function formatCode(string $letter, int $number): string
    {
        return sprintf('%s-%03d', $letter, $number);
    }
}
