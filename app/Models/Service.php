<?php

namespace App\Models;

use App\Tenancy\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Service extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsToMany<Counter, $this> */
    public function counters(): BelongsToMany
    {
        return $this->belongsToMany(Counter::class);
    }

    public function isBookable(): bool
    {
        return $this->is_active && $this->deposit_amount > 0;
    }
}
