<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Counter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Counter>
 */
class CounterFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'business_id' => fn (array $attributes) => Branch::withoutGlobalScopes()->findOrFail($attributes['branch_id'])->business_id,
            'number' => fake()->unique()->numberBetween(1, 9999),
        ];
    }
}
