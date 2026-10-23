<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'business_id' => fn (array $attributes) => Branch::withoutGlobalScopes()->findOrFail($attributes['branch_id'])->business_id,
            'name' => fake()->word(),
            'code' => fake()->unique()->randomElement(range('A', 'Z')),
            'avg_duration_minutes' => 15,
            'deposit_amount' => 0,
            'is_active' => true,
        ];
    }

    public function bookable(int $deposit = 25000): static
    {
        return $this->state(['deposit_amount' => $deposit]);
    }
}
