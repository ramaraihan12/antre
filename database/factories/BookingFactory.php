<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $start = now()->addDay()->setTime(10, 0);

        return [
            'service_id' => Service::factory()->bookable(),
            'branch_id' => fn (array $a) => Service::withoutGlobalScopes()->findOrFail($a['service_id'])->branch_id,
            'business_id' => fn (array $a) => Service::withoutGlobalScopes()->findOrFail($a['service_id'])->business_id,
            'customer_name' => fake()->name(),
            'customer_phone' => '08'.fake()->numerify('##########'),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(15),
            'status' => BookingStatus::PendingPayment,
            'deposit_amount' => 25000,
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function status(BookingStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
