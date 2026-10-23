<?php

namespace App\Http\Controllers\Staff;

use App\Booking\BookingService;
use App\Exceptions\QueueException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Counter;
use App\Models\Ticket;
use App\Queue\QueueDesk;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QueueActionController extends Controller
{
    public function __construct(private readonly QueueDesk $desk) {}

    public function callNext(Counter $counter): RedirectResponse
    {
        $this->authorize('operate', $counter);

        return $this->attempt(function () use ($counter) {
            $ticket = $this->desk->callNext($counter);

            return $ticket ? "Memanggil {$ticket->code}" : 'Tidak ada antrean yang menunggu.';
        });
    }

    public function recall(Ticket $ticket): RedirectResponse
    {
        $this->authorize('operate', $ticket);

        return $this->attempt(function () use ($ticket) {
            $this->desk->recall($ticket);

            return "Memanggil ulang {$ticket->code}";
        });
    }

    public function skip(Ticket $ticket): RedirectResponse
    {
        $this->authorize('operate', $ticket);

        return $this->attempt(function () use ($ticket) {
            $this->desk->skip($ticket);

            return "{$ticket->code} ditandai tidak hadir";
        });
    }

    public function done(Ticket $ticket): RedirectResponse
    {
        $this->authorize('operate', $ticket);

        return $this->attempt(function () use ($ticket) {
            $this->desk->done($ticket);

            return "{$ticket->code} selesai";
        });
    }

    public function checkIn(Request $request, BookingService $bookings): RedirectResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:64']]);

        // Looked up through the tenant scope, so another business's reference is simply not found.
        $booking = Booking::query()->where('order_id', strtoupper(trim($data['reference'])))->first();

        if ($booking === null) {
            return back()->withErrors(['reference' => 'Booking tidak ditemukan.']);
        }

        $this->authorize('checkIn', $booking);

        return $this->attempt(fn () => 'Check-in berhasil, nomor antrean '.$bookings->checkIn($booking)->code);
    }

    /** @param  Closure(): string  $action */
    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return back()->with('status', $action());
        } catch (QueueException $e) {
            return back()->withErrors(['queue' => $e->getMessage()]);
        }
    }
}
