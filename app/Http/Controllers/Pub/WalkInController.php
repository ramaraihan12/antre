<?php

namespace App\Http\Controllers\Pub;

use App\Enums\TicketStatus;
use App\Exceptions\QueueException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Service;
use App\Models\Ticket;
use App\Queue\TicketIssuer;
use App\Queue\WaitEstimator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WalkInController extends Controller
{
    public function show(Branch $branch, WaitEstimator $estimator): Response
    {
        $services = $branch->services()->where('is_active', true)->orderBy('code')->get();

        $waiting = Ticket::query()
            ->where('branch_id', $branch->id)
            ->whereDate('queue_date', $branch->today())
            ->where('status', TicketStatus::Waiting)
            ->selectRaw('service_id, count(*) as total')
            ->groupBy('service_id')
            ->pluck('total', 'service_id');

        return Inertia::render('queue/join', [
            'branch' => ['name' => $branch->name, 'slug' => $branch->slug, 'city' => $branch->city],
            'services' => $services->map(fn (Service $service) => [
                'id' => $service->id,
                'code' => $service->code,
                'name' => $service->name,
                'waiting' => (int) ($waiting[$service->id] ?? 0),
                'average_minutes' => $estimator->averageMinutes($service),
            ]),
        ]);
    }

    public function store(Request $request, Branch $branch, TicketIssuer $issuer): RedirectResponse
    {
        $data = $request->validate(['service_id' => ['required', 'integer']]);

        $service = $branch->services()->find($data['service_id']);

        if ($service === null) {
            return back()->withErrors(['service_id' => 'Layanan tidak ditemukan.']);
        }

        try {
            $ticket = $issuer->issue($branch, $service);
        } catch (QueueException $e) {
            return back()->withErrors(['service_id' => $e->getMessage()]);
        }

        return redirect()->route('ticket.show', $ticket->public_id);
    }
}
