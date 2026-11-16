<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Counter;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fills a throwaway database for the k6 load test, see loadtest/README.md. Writes a manifest
 * that the k6 scripts read: branches, counters, the staff of each counter and paid bookings.
 */
class LoadTestSeed extends Command
{
    public const SLUG = 'loadtest';

    protected $signature = 'antre:loadtest-seed
        {--branches=3 : Branches, each with its own TV display}
        {--services=3 : Services per branch (1 to 8)}
        {--counters=4 : Counters per branch}
        {--staff=2 : Staff users per counter, two lets the test double click}
        {--tickets=300 : Tickets already waiting in each branch}
        {--bookings=40 : Paid bookings for today in each branch, for check-in}
        {--manifest= : Where to write the manifest (default loadtest/out/manifest.json)}';

    protected $description = 'Seed branches, counters, staff, queued tickets and paid bookings for the k6 load test';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to seed load test data when APP_ENV is production.');

            return self::FAILURE;
        }

        $branches = max(1, (int) $this->option('branches'));
        $services = min(8, max(1, (int) $this->option('services')));
        $counters = max(1, (int) $this->option('counters'));
        $staff = max(1, (int) $this->option('staff'));
        $tickets = max(0, (int) $this->option('tickets'));
        $bookings = max(0, (int) $this->option('bookings'));
        $path = (string) ($this->option('manifest') ?: base_path('loadtest/out/manifest.json'));

        $this->removeEarlierRun();

        // A new password every run, so the staff accounts of a leftover database are not guessable.
        $password = Str::random(24);
        $hash = Hash::make($password);

        $business = Business::create(['name' => 'Load Test', 'slug' => self::SLUG]);
        $manifest = ['password' => $password, 'branches' => []];

        foreach (range(1, $branches) as $b) {
            $manifest['branches'][] = $this->branch($business, $b, $services, $counters, $staff, $tickets, $bookings, $hash);
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->line(sprintf(
            'seeded %d branches, %d counters, %d staff users, %d waiting tickets, %d paid bookings',
            $branches,
            $branches * $counters,
            $branches * $counters * $staff,
            $branches * $tickets,
            $branches * $bookings,
        ));
        $this->line("manifest: $path");

        return self::SUCCESS;
    }

    private function removeEarlierRun(): void
    {
        $old = Business::withoutGlobalScopes()->where('slug', self::SLUG)->first();

        if ($old === null) {
            return;
        }

        User::query()->where('business_id', $old->id)->delete();
        $old->delete();
    }

    /** @return array<string, mixed> */
    private function branch(Business $business, int $n, int $serviceCount, int $counterCount, int $staffCount, int $ticketCount, int $bookingCount, string $hash): array
    {
        // UTC, so a run is not cut in two by midnight in Jakarta (17:00 UTC).
        $branch = Branch::create([
            'business_id' => $business->id,
            'name' => "Load Test $n",
            'slug' => "lt-$n",
            'timezone' => 'UTC',
            'opening_hours' => array_fill_keys(range(0, 6), ['00:00', '23:59']),
        ]);

        $services = collect(range(0, $serviceCount - 1))->map(fn (int $i) => Service::create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'name' => 'Layanan '.chr(65 + $i),
            'code' => chr(65 + $i),
        ]));

        $counters = [];
        $users = [];

        foreach (range(1, $counterCount) as $number) {
            $counter = Counter::create(['business_id' => $business->id, 'branch_id' => $branch->id, 'number' => $number]);
            $counter->services()->attach($services->pluck('id'));

            $emails = [];
            foreach (range(1, $staffCount) as $s) {
                $emails[] = "lt-$n-$number-$s@loadtest.test";
                $users[] = [
                    'name' => "Staff $n-$number-$s",
                    'email' => end($emails),
                    'password' => $hash,
                    'email_verified_at' => now(),
                    'business_id' => $business->id,
                    'branch_id' => $branch->id,
                    'role' => Role::Staff->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            $counters[] = ['id' => $counter->id, 'staff' => $emails];
        }

        User::query()->insert($users);
        $this->tickets($branch, $services->all(), $ticketCount);

        return [
            'id' => $branch->id,
            'slug' => $branch->slug,
            'channel' => "display.{$branch->id}",
            'services' => $services->pluck('id')->all(),
            'counters' => $counters,
            'bookings' => $this->bookings($branch, $services->first(), $bookingCount),
        ];
    }

    /**
     * About 5% priority, services picked at random but always numbered one after the other.
     *
     * @param  list<Service>  $services
     */
    private function tickets(Branch $branch, array $services, int $count): void
    {
        $numbers = array_fill_keys(array_map(fn (Service $s) => $s->id, $services), 0);
        $rows = [];
        mt_srand(2026 + $branch->id);

        for ($i = 0; $i < $count; $i++) {
            $service = $services[mt_rand(0, count($services) - 1)];
            $number = ++$numbers[$service->id];
            $rows[] = [
                'public_id' => strtolower((string) Str::ulid()),
                'business_id' => $branch->business_id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'queue_date' => $branch->today(),
                'number' => $number,
                'code' => Ticket::formatCode($service->code, $number),
                'status' => TicketStatus::Waiting->value,
                'is_priority' => mt_rand(1, 20) === 1,
                'call_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            Ticket::query()->insert($chunk);
        }
    }

    /** @return list<string> the references to check in */
    private function bookings(Branch $branch, Service $service, int $count): array
    {
        $references = [];
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $references[] = $order = 'LT-'.strtoupper(Str::random(10));
            $rows[] = [
                'public_id' => strtolower((string) Str::ulid()),
                'business_id' => $branch->business_id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'customer_name' => 'Pelanggan '.($i + 1),
                'customer_phone' => '0800000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'starts_at' => now(),
                'ends_at' => now()->addMinutes(15),
                'status' => BookingStatus::Paid->value,
                'deposit_amount' => 0,
                'order_id' => $order,
                'expires_at' => now()->addDay(),
                'paid_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            Booking::query()->insert($chunk);
        }

        return $references;
    }
}
