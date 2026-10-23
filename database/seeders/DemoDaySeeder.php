<?php

namespace Database\Seeders;

use App\Enums\TicketStatus;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketCall;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fills today with a believable morning of finished tickets so the owner dashboard has
 * numbers to show. The times follow the branch opening hours, not the clock, so running it
 * early in the morning still gives the same shape (some timestamps are then in the future).
 * Run it with: php artisan db:seed --class=DemoDaySeeder
 */
class DemoDaySeeder extends Seeder
{
    /** Relative arrival weight for each hour after opening. */
    private const HOURS = [3, 6, 7, 5, 3, 2, 4, 5, 3, 2, 1];

    public function run(): void
    {
        mt_srand(2026);

        foreach (Branch::withoutGlobalScopes()->get() as $branch) {
            $this->dayFor($branch);
        }
    }

    private function dayFor(Branch $branch): void
    {
        $services = Service::withoutGlobalScopes()->where('branch_id', $branch->id)->get();
        $counters = Counter::withoutGlobalScopes()->where('branch_id', $branch->id)->orderBy('number')->get();
        $hours = $branch->hoursOn($branch->now()) ?? $branch->opening_hours[1] ?? ['09:00', '17:00'];
        $open = Carbon::parse($branch->today().' '.$hours[0], $branch->timezone)->utc();

        // Aim for about 70% of the counters' capacity over the day, so peaks queue up and quiet hours do not.
        $openMinutes = $open->diffInMinutes(Carbon::parse($branch->today().' '.$hours[1], $branch->timezone)->utc());
        $total = (int) round($counters->count() * $openMinutes * 0.7 / $services->avg('avg_duration_minutes'));
        $numbers = [];
        $counterFree = $counters->mapWithKeys(fn (Counter $c) => [$c->id => $open->copy()])->all();

        $arrivals = $this->arrivalTimes($open, $total);

        foreach ($arrivals as $index => $arrival) {
            $service = $services->random();
            $number = $numbers[$service->id] = ($numbers[$service->id] ?? 0) + 1;

            $counterId = array_keys($counterFree, min($counterFree))[0];
            $calledAt = $arrival->copy()->max($counterFree[$counterId])->addSeconds(mt_rand(5, 40));
            $serviceMinutes = max(4, $service->avg_duration_minutes * (mt_rand(65, 135) / 100));
            $noShow = mt_rand(1, 100) <= 8;
            $isLast = $index >= $total - 3;

            $attributes = [
                'business_id' => $branch->business_id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'queue_date' => $branch->today(),
                'number' => $number,
                'code' => Ticket::formatCode($service->code, $number),
                'is_priority' => mt_rand(1, 100) <= 12,
                'call_count' => 0,
                'created_at' => $arrival,
                'updated_at' => $arrival,
            ];

            if ($isLast) {
                Ticket::create($attributes + ['status' => TicketStatus::Waiting]);

                continue;
            }

            $doneAt = $calledAt->copy()->addSeconds((int) ($serviceMinutes * 60));
            $counterFree[$counterId] = $noShow ? $calledAt->copy()->addMinutes(2) : $doneAt;

            $ticket = Ticket::create($attributes + [
                'status' => $noShow ? TicketStatus::Skipped : TicketStatus::Done,
                'counter_id' => $counterId,
                'called_at' => $calledAt,
                'done_at' => $noShow ? null : $doneAt,
                'call_count' => $noShow ? 2 : 1,
            ]);

            TicketCall::create([
                'business_id' => $branch->business_id,
                'branch_id' => $branch->id,
                'ticket_id' => $ticket->id,
                'counter_id' => $counterId,
                'created_at' => $calledAt,
            ]);
        }
    }

    /** @return Collection<int, Carbon> */
    private function arrivalTimes(Carbon $open, int $count): Collection
    {
        $slots = [];
        foreach (self::HOURS as $hour => $weight) {
            $slots = [...$slots, ...array_fill(0, $weight, $hour)];
        }

        return collect(range(1, $count))
            ->map(fn () => $open->copy()->addHours($slots[array_rand($slots)])->addMinutes(mt_rand(0, 59))->addSeconds(mt_rand(0, 59)))
            ->sort()
            ->values();
    }
}
