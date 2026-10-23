<?php

namespace Database\Factories;

use App\Enums\TicketStatus;
use App\Models\Branch;
use App\Models\Service;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'branch_id' => fn (array $a) => Service::withoutGlobalScopes()->findOrFail($a['service_id'])->branch_id,
            'business_id' => fn (array $a) => Branch::withoutGlobalScopes()->findOrFail($a['branch_id'])->business_id,
            'queue_date' => now()->toDateString(),
            'number' => fake()->unique()->numberBetween(1, 999),
            'code' => fn (array $a) => Ticket::formatCode('A', $a['number']),
            'status' => TicketStatus::Waiting,
            'is_priority' => false,
            'call_count' => 0,
        ];
    }
}
