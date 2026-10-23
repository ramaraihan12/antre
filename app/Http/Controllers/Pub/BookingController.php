<?php

namespace App\Http\Controllers\Pub;

use App\Booking\BookingService;
use App\Booking\SlotCalculator;
use App\Booking\SlotUnavailable;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Service;
use App\Payments\FakePayment;
use App\Payments\Payments;
use App\Payments\SnapGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BookingController extends Controller
{
    public function create(Branch $branch): Response
    {
        $today = Carbon::parse($branch->today(), $branch->timezone);

        $days = collect(range(0, (int) config('antre.booking_max_days_ahead')))
            ->map(fn (int $offset) => $today->copy()->addDays($offset))
            ->filter(fn (Carbon $day) => $branch->hoursOn($day) !== null)
            ->map(fn (Carbon $day) => [
                'date' => $day->toDateString(),
                'weekday' => $day->locale('id')->translatedFormat('D'),
                'day' => $day->format('j'),
                'month' => $day->locale('id')->translatedFormat('M'),
            ])
            ->values();

        return Inertia::render('book/create', [
            'branch' => ['name' => $branch->name, 'slug' => $branch->slug, 'city' => $branch->city],
            'services' => $branch->services()->where('is_active', true)->where('deposit_amount', '>', 0)->orderBy('code')->get()
                ->map(fn (Service $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'minutes' => $s->avg_duration_minutes,
                    'deposit' => $s->deposit_amount,
                ]),
            'days' => $days,
        ]);
    }

    public function slots(Request $request, Branch $branch, SlotCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $service = $branch->services()->find($data['service_id']);
        abort_if($service === null, 404);

        return response()->json([
            'slots' => $calculator->forDay($branch, $service, Carbon::parse($data['date'], $branch->timezone)),
        ]);
    }

    public function store(Request $request, Branch $branch, BookingService $bookings): RedirectResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9 ()-]{8,20}$/'],
        ], [
            'phone.regex' => 'Nomor telepon tidak valid.',
        ]);

        $service = $branch->services()->find($data['service_id']);
        if ($service === null) {
            return back()->withErrors(['service_id' => 'Layanan tidak ditemukan.']);
        }

        try {
            $booking = $bookings->create($branch, $service, $data['date'], $data['time'], $data['name'], $data['phone']);
        } catch (SlotUnavailable $e) {
            return back()->withErrors(['time' => $e->getMessage()]);
        }

        return redirect()->route('booking.show', $booking->public_id);
    }

    public function show(Booking $booking, Payments $payments, FakePayment $fake): Response
    {
        $booking->load(['service', 'branch', 'ticket']);

        return Inertia::render('book/show', [
            'booking' => [
                'public_id' => $booking->public_id,
                'order_id' => $booking->order_id,
                'status' => $booking->status->value,
                'status_label' => $booking->status->label(),
                'service' => $booking->service->name,
                'branch' => $booking->branch->name,
                'customer' => $booking->customer_name,
                'deposit' => $booking->deposit_amount,
                'date' => $booking->localStart()->locale('id')->translatedFormat('l, j F Y'),
                'time' => $booking->localStart()->format('H:i'),
                'expires_at' => $booking->expires_at->toIso8601String(),
                'ticket_public_id' => $booking->ticket?->public_id,
            ],
            'payment' => [
                'driver' => $payments->driver(),
                'client_key' => $payments->clientKey(),
                'snap_url' => $payments->snapScriptUrl(),
                'fake_available' => $fake->available(),
            ],
        ]);
    }

    /** Creates the Snap payment session and returns its token to the page. */
    public function pay(Booking $booking, SnapGateway $gateway): RedirectResponse
    {
        if ($booking->status !== BookingStatus::PendingPayment || $booking->expires_at->isPast()) {
            return back()->withErrors(['payment' => 'Booking ini tidak bisa dibayar lagi.']);
        }

        try {
            $token = $booking->snap_token ?? $gateway->createToken($booking);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['payment' => 'Pembayaran belum bisa dimulai. Coba lagi sebentar.']);
        }

        $booking->update(['snap_token' => $token]);

        return back()->with('snap_token', $token);
    }

    /** Local demo only: sends a signed notification to the same code the real webhook uses. */
    public function fakePay(Booking $booking, FakePayment $fake): RedirectResponse
    {
        abort_unless($fake->available(), 404);

        $outcome = $fake->send($booking);

        return back()->with('status', $outcome === 'applied' ? 'Pembayaran simulasi berhasil.' : "Pembayaran simulasi: $outcome");
    }
}
