<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = 'Cabang '.fake()->unique()->lastName();

        return [
            'business_id' => Business::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'city' => 'Salatiga',
            'timezone' => 'Asia/Jakarta',
            // Open every day so tests do not depend on the weekday they run on.
            'opening_hours' => array_fill_keys(range(0, 6), ['09:00', '17:00']),
        ];
    }
}
