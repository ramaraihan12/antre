<?php

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Counter;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketCall;
use App\Simulation\Percentiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class Simulate extends Command
{
    protected $signature = 'antre:simulate
        {--counters=4 : Counters calling at the same time, each in its own PHP process}
        {--tickets=400 : Tickets waiting when the morning starts}
        {--think=0 : Milliseconds each counter keeps a ticket before finishing it}
        {--keep : Keep the simulation data instead of deleting it}';

    protected $description = 'Run a busy morning: counters call tickets concurrently, then check nothing was called twice';

    public function handle(): int
    {
        $counters = max(1, (int) $this->option('counters'));
        $tickets = max(1, (int) $this->option('tickets'));
        $think = max(0, (int) $this->option('think'));

        $this->line('$ php artisan antre:simulate --counters='.$counters.' --tickets='.$tickets.($think ? " --think=$think" : ''));
        $this->line('database: '.DB::getDriverName().' ('.DB::selectOne('select version() as v')->v.')');

        [$business, $counterIds] = $this->seed($counters, $tickets);

        try {
            return $this->runWorkers($business, $counterIds, $tickets, $think);
        } finally {
            if (! $this->option('keep')) {
                $business->delete();
            }
        }
    }

    /**
     * @param  list<int>  $counterIds
     */
    private function runWorkers(Business $business, array $counterIds, int $tickets, int $think): int
    {
        $startAt = microtime(true) + 1.5;
        $processes = [];

        foreach ($counterIds as $counterId) {
            $process = new Process(
                [PHP_BINARY, 'artisan', 'antre:simulate-worker', (string) $counterId, '--start-at='.$startAt, '--think='.$think],
                base_path(),
                ['BROADCAST_CONNECTION' => 'null'],
                timeout: 300,
            );
            $process->start();
            $processes[$counterId] = $process;
        }

        foreach ($processes as $process) {
            $process->wait();
        }
        $wallMs = (microtime(true) - $startAt) * 1000;

        $latencies = [];
        $called = [];
        $perCounter = [];
        $errors = 0;

        foreach ($processes as $counterId => $process) {
            $perCounter[$counterId] = 0;

            foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                if (str_starts_with($line, 'error')) {
                    $errors++;
                    $this->error("counter $counterId: $line");

                    continue;
                }
                [$ticketId, $ms] = explode(' ', $line);
                $latencies[] = (float) $ms;
                $called[] = (int) $ticketId;
                $perCounter[$counterId]++;
            }

            if (! $process->isSuccessful()) {
                $errors++;
                $this->error("counter $counterId exited with code {$process->getExitCode()}: ".trim($process->getErrorOutput()));
            }
        }

        // Check the database, not just what the workers say.
        $duplicatesSeen = count($called) - count(array_unique($called));
        $multiCalled = TicketCall::query()
            ->where('business_id', $business->id)
            ->where('kind', 'call')
            ->select('ticket_id')
            ->groupBy('ticket_id')
            ->havingRaw('count(*) > 1')
            ->toBase()
            ->getCountForPagination();
        $done = Ticket::query()->where('business_id', $business->id)->where('status', TicketStatus::Done)->count();
        $stillWaiting = Ticket::query()->where('business_id', $business->id)->where('status', TicketStatus::Waiting)->count();

        $this->newLine();
        $this->line(sprintf('tickets waiting at start   %d', $tickets));
        $this->line(sprintf('calls made                 %d', count($called)));
        $this->line(sprintf('tickets done               %d', $done));
        $this->line(sprintf('still waiting              %d', $stillWaiting));
        $this->line(sprintf('called more than once      %d (workers saw %d, database has %d)', $duplicatesSeen + $multiCalled, $duplicatesSeen, $multiCalled));
        $this->line(sprintf('calls per counter          %s', implode(' / ', $perCounter)));
        $this->line(sprintf('wall time                  %.2f s (%.0f calls/s)', $wallMs / 1000, $wallMs > 0 ? count($called) / ($wallMs / 1000) : 0));

        if ($latencies !== []) {
            $this->line(sprintf(
                'call-next latency          p50 %.1f ms   p95 %.1f ms   p99 %.1f ms   max %.1f ms',
                Percentiles::of($latencies, 50),
                Percentiles::of($latencies, 95),
                Percentiles::of($latencies, 99),
                max($latencies),
            ));
        }

        $ok = $errors === 0 && $duplicatesSeen === 0 && $multiCalled === 0 && $done === $tickets && count($called) === $tickets;
        $this->line($ok ? 'result: every ticket was called exactly once' : 'result: FAILED');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Creates a made-up business with one branch, two services and the counters.
     * Tickets are inserted in bulk, 70% service A, 30% B, about 1 in 20 with priority.
     *
     * @return array{0: Business, 1: list<int>}
     */
    private function seed(int $counters, int $tickets): array
    {
        $business = Business::create(['name' => 'Simulasi Pagi', 'slug' => 'simulasi-'.Str::lower(Str::random(6))]);
        $branch = Branch::create([
            'business_id' => $business->id,
            'name' => 'Cabang Simulasi',
            'slug' => $business->slug,
            'timezone' => 'Asia/Jakarta',
            'opening_hours' => array_fill_keys(range(0, 6), ['08:00', '17:00']),
        ]);

        $a = Service::create(['business_id' => $business->id, 'branch_id' => $branch->id, 'name' => 'Umum', 'code' => 'A']);
        $b = Service::create(['business_id' => $business->id, 'branch_id' => $branch->id, 'name' => 'Khusus', 'code' => 'B']);

        $counterIds = [];
        foreach (range(1, $counters) as $number) {
            $counter = Counter::create(['business_id' => $business->id, 'branch_id' => $branch->id, 'number' => $number]);
            $counter->services()->attach([$a->id, $b->id]);
            $counterIds[] = $counter->id;
        }

        $today = $branch->today();
        $rows = [];
        $numbers = [$a->id => 0, $b->id => 0];
        mt_srand(2026);

        for ($i = 0; $i < $tickets; $i++) {
            $service = mt_rand(1, 10) <= 7 ? $a : $b;
            $number = ++$numbers[$service->id];
            $rows[] = [
                'public_id' => strtolower((string) Str::ulid()),
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'queue_date' => $today,
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

        return [$business, $counterIds];
    }
}
