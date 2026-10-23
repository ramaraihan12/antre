<?php

namespace App\Console\Commands;

use App\Exceptions\QueueException;
use App\Models\Counter;
use App\Queue\QueueDesk;
use Illuminate\Console\Command;

/**
 * One simulated staff member, run as its own PHP process by antre:simulate and by the
 * concurrency test. Prints one line per call: "<ticket id> <milliseconds>".
 */
class SimulateWorker extends Command
{
    protected $signature = 'antre:simulate-worker
        {counter : Counter id}
        {--start-at= : Unix time (float) to wait for, so all workers start together}
        {--think=0 : Milliseconds to keep a ticket before finishing it}';

    protected $description = 'Internal: call and finish tickets at one counter until the queue is empty';

    protected $hidden = true;

    public function handle(QueueDesk $desk): int
    {
        // Latency should measure the database, not a websocket server that is not running.
        config(['broadcasting.default' => 'null']);

        $counter = Counter::query()->findOrFail($this->argument('counter'));
        $think = (int) $this->option('think');

        if ($this->option('start-at')) {
            $start = (float) $this->option('start-at');
            while (microtime(true) < $start) {
                usleep(200);
            }
        }

        while (true) {
            $t0 = hrtime(true);

            try {
                $ticket = $desk->callNext($counter);
            } catch (QueueException $e) {
                $this->line('error '.$e->getMessage());

                return self::FAILURE;
            }

            $ms = (hrtime(true) - $t0) / 1e6;

            if ($ticket === null) {
                return self::SUCCESS;
            }

            $this->line(sprintf('%d %.3f', $ticket->id, $ms));

            if ($think > 0) {
                usleep($think * 1000);
            }

            $desk->done($ticket);
        }
    }
}
