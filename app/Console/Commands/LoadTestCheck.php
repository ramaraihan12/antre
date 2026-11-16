<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Run after a k6 load test, see loadtest/README.md. Reads the load test business straight from
 * the database and proves the queue stayed consistent. It does not trust anything k6 reports.
 */
class LoadTestCheck extends Command
{
    protected $signature = 'antre:loadtest-check
        {--allow-open : Do not fail on tickets still waiting or called (use when the run was cut short)}
        {--json= : Also write the result as JSON to this path}';

    protected $description = 'Check that every ticket of the load test was called exactly once and none sits on two counters';

    public function handle(): int
    {
        $business = DB::table('businesses')->where('slug', LoadTestSeed::SLUG)->first();

        if ($business === null) {
            $this->error('No load test data found, run antre:loadtest-seed first.');

            return self::FAILURE;
        }

        $id = (int) $business->id;
        $count = fn (string $sql) => (int) DB::selectOne("select count(*) as n from ($sql) q", [$id])->n;

        $byStatus = DB::table('tickets')->where('business_id', $id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $total = (int) $byStatus->sum();
        $open = (int) ($byStatus['waiting'] ?? 0) + (int) ($byStatus['called'] ?? 0);

        $checks = [
            [
                'every ticket that reached a counter has exactly one call',
                $count("select t.id from tickets t left join ticket_calls c on c.ticket_id = t.id and c.kind = 'call'
                    where t.business_id = ? and t.status in ('called', 'done', 'skipped')
                    group by t.id having count(c.id) <> 1"),
            ],
            [
                'no ticket was called more than once',
                $count("select ticket_id from ticket_calls where business_id = ? and kind = 'call' group by ticket_id having count(*) > 1"),
            ],
            [
                'no ticket was called on two different counters',
                $count('select ticket_id from ticket_calls where business_id = ? group by ticket_id having count(distinct counter_id) > 1'),
            ],
            [
                'a ticket that was never called has no call and no counter',
                $count("select id from tickets t where business_id = ? and status in ('waiting', 'cancelled')
                    and (counter_id is not null or exists (select 1 from ticket_calls c where c.ticket_id = t.id))"),
            ],
            [
                'the counter on a ticket is the counter that called it',
                $count("select t.id from tickets t join ticket_calls c on c.ticket_id = t.id and c.kind = 'call'
                    where t.business_id = ? and t.counter_id is distinct from c.counter_id"),
            ],
            [
                'no counter holds two called tickets',
                $count("select counter_id from tickets where business_id = ? and status = 'called' group by counter_id having count(*) > 1"),
            ],
            [
                'ticket numbers are not repeated within a service and day',
                $count('select 1 from tickets where business_id = ? group by branch_id, service_id, queue_date, number having count(*) > 1'),
            ],
            [
                'every checked-in booking has exactly one ticket',
                $count("select b.id from bookings b left join tickets t on t.booking_id = b.id
                    where b.business_id = ? and b.status = 'checked_in' group by b.id having count(t.id) <> 1"),
            ],
        ];

        if (! $this->option('allow-open')) {
            $checks[] = [
                'nothing is left waiting or called (the run drained the queue)',
                $open,
            ];
        }

        $failed = 0;
        $rows = [];
        foreach ($checks as [$label, $bad]) {
            $rows[] = ['label' => $label, 'violations' => $bad, 'pass' => $bad === 0];
            $failed += $bad === 0 ? 0 : 1;
        }

        $calls = (int) DB::table('ticket_calls')->where('business_id', $id)->where('kind', 'call')->count();
        $recalls = (int) DB::table('ticket_calls')->where('business_id', $id)->where('kind', 'recall')->count();
        $checkedIn = (int) DB::table('bookings')->where('business_id', $id)->where('status', 'checked_in')->count();

        $this->line('tickets '.$total.': '.($byStatus->map(fn ($n, $s) => "$s $n")->implode(', ') ?: 'none'));
        $this->line("calls $calls, recalls $recalls, bookings checked in $checkedIn");
        $this->newLine();

        foreach ($rows as $row) {
            $this->line(sprintf('%s  %s%s', $row['pass'] ? 'PASS' : 'FAIL', $row['label'], $row['pass'] ? '' : " ({$row['violations']})"));
        }

        $this->newLine();
        $this->line(match (true) {
            $failed > 0 => "result: FAIL, $failed check(s) broken",
            $open > 0 => "result: PASS, no ticket was called twice ($open still open)",
            default => 'result: PASS, every ticket was called exactly once',
        });

        if ($path = $this->option('json')) {
            File::ensureDirectoryExists(dirname((string) $path));
            File::put((string) $path, json_encode([
                'pass' => $failed === 0,
                'tickets' => $byStatus,
                'calls' => $calls,
                'recalls' => $recalls,
                'checked_in' => $checkedIn,
                'checks' => $rows,
            ], JSON_PRETTY_PRINT));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
