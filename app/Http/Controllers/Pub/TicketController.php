<?php

namespace App\Http\Controllers\Pub;

use App\Exceptions\QueueException;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Queue\QueueDesk;
use App\Queue\WaitEstimator;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    public function show(Ticket $ticket, WaitEstimator $estimator): Response
    {
        $ticket->load(['service', 'counter', 'branch']);

        return Inertia::render('queue/ticket', [
            'ticket' => [
                'public_id' => $ticket->public_id,
                'code' => $ticket->code,
                'status' => $ticket->status->value,
                'service' => $ticket->service->name,
                'is_priority' => $ticket->is_priority,
                'counter' => $ticket->counter?->label(),
            ],
            'estimate' => $estimator->forTicket($ticket),
            'branch' => ['id' => $ticket->branch->id, 'name' => $ticket->branch->name, 'slug' => $ticket->branch->slug],
        ]);
    }

    public function cancel(Ticket $ticket, QueueDesk $desk): RedirectResponse
    {
        try {
            $desk->cancel($ticket);
        } catch (QueueException $e) {
            return back()->withErrors(['ticket' => $e->getMessage()]);
        }

        return back()->with('status', 'Nomor dibatalkan.');
    }
}
