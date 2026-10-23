<?php

namespace App\Http\Controllers\Staff;

use App\Enums\BookingStatus;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConsoleController extends Controller
{
    /** Pick a counter to work at. */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $branches = Branch::query()
            ->when(! $user->isOwner() && $user->branch_id, fn ($q) => $q->whereKey($user->branch_id))
            ->with(['counters' => fn ($q) => $q->orderBy('number')->with('services:id,code,name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'slug' => $branch->slug,
                'counters' => $branch->counters->map(fn (Counter $counter) => [
                    'id' => $counter->id,
                    'label' => $counter->label(),
                    'services' => $counter->services->pluck('code')->sort()->values(),
                ]),
            ]);

        return Inertia::render('console/index', ['branches' => $branches]);
    }

    public function show(Counter $counter): Response
    {
        $this->authorize('operate', $counter);

        $counter->load(['branch', 'services:id,code,name']);
        $branch = $counter->branch;
        $today = $branch->today();
        $serviceIds = $counter->services->pluck('id');

        $current = Ticket::query()
            ->where('counter_id', $counter->id)
            ->where('status', TicketStatus::Called)
            ->with('service:id,code,name')
            ->first();

        $waitingQuery = Ticket::query()
            ->where('branch_id', $branch->id)
            ->whereDate('queue_date', $today)
            ->where('status', TicketStatus::Waiting)
            ->whereIn('service_id', $serviceIds);

        $waiting = (clone $waitingQuery)
            ->inCallingOrder()
            ->with('service:id,code,name')
            ->limit(8)
            ->get();

        $bookings = Booking::query()
            ->where('branch_id', $branch->id)
            ->where('status', BookingStatus::Paid)
            ->with(['service:id,name', 'branch:id,timezone'])
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Booking $b) => $b->localStart()->toDateString() === $today)
            ->values();

        return Inertia::render('console/show', [
            'counter' => ['id' => $counter->id, 'label' => $counter->label()],
            'branch' => ['id' => $branch->id, 'name' => $branch->name, 'slug' => $branch->slug],
            'services' => $counter->services->map(fn ($s) => ['code' => $s->code, 'name' => $s->name]),
            'current' => $current ? $this->ticket($current) : null,
            'waiting' => $waiting->map(fn (Ticket $t) => $this->ticket($t)),
            'waiting_count' => $waitingQuery->count(),
            'served_today' => Ticket::query()
                ->where('counter_id', $counter->id)
                ->whereDate('queue_date', $today)
                ->where('status', TicketStatus::Done)
                ->count(),
            'bookings' => $bookings->map(fn (Booking $b) => [
                'order_id' => $b->order_id,
                'customer' => $b->customer_name,
                'time' => $b->localStart()->format('H:i'),
                'service' => $b->service->name,
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function ticket(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'code' => $ticket->code,
            'service' => $ticket->service->name,
            'is_priority' => $ticket->is_priority,
            'call_count' => $ticket->call_count,
            'called_at' => $ticket->called_at?->toIso8601String(),
        ];
    }
}
