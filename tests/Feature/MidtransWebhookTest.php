<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\PaymentNotification;
use App\Payments\FakePayment;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-06-15 01:00:00');
    $this->s = scenario();
    $this->booking = Booking::factory()->create([
        'service_id' => $this->s->a->id,
        'starts_at' => '2026-06-15 03:00:00',
        'ends_at' => '2026-06-15 03:15:00',
        'expires_at' => '2026-06-15 01:15:00',
        'deposit_amount' => 25000,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/** A notification signed the way Midtrans does it, built without using app code. */
function notification(Booking $booking, string $status = 'settlement', array $override = [], string $key = 'SB-Mid-server-test'): array
{
    $payload = array_merge([
        'order_id' => $booking->order_id,
        'transaction_id' => 'trx-1',
        'transaction_status' => $status,
        'status_code' => $status === 'settlement' ? '200' : '201',
        'gross_amount' => '25000.00',
        'payment_type' => 'bank_transfer',
        'fraud_status' => 'accept',
    ], $override);

    $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$key);

    return $payload;
}

beforeEach(function () {
    config(['antre.payments.driver' => 'midtrans', 'antre.payments.midtrans.server_key' => 'SB-Mid-server-test']);
});

it('marks a booking paid on a correctly signed settlement', function () {
    $this->postJson('/webhooks/midtrans', notification($this->booking))
        ->assertOk()
        ->assertJson(['status' => 'ok', 'outcome' => 'applied']);

    expect($this->booking->fresh())
        ->status->toBe(BookingStatus::Paid)
        ->paid_at->not->toBeNull();
});

it('rejects a wrong signature and changes nothing', function () {
    $payload = notification($this->booking, key: 'some-other-key');

    $this->postJson('/webhooks/midtrans', $payload)->assertForbidden();

    expect($this->booking->fresh()->status)->toBe(BookingStatus::PendingPayment)
        ->and(PaymentNotification::count())->toBe(0);
});

it('rejects a payload whose amount was changed after signing', function () {
    $payload = notification($this->booking);
    $payload['gross_amount'] = '1.00';

    $this->postJson('/webhooks/midtrans', $payload)->assertForbidden();

    expect($this->booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
});

it('rejects a payload with missing fields', function () {
    $payload = notification($this->booking);
    unset($payload['signature_key']);

    $this->postJson('/webhooks/midtrans', $payload)->assertStatus(400);
});

it('accepts form encoded bodies too', function () {
    $this->post('/webhooks/midtrans', notification($this->booking))->assertOk();

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Paid);
});

it('is idempotent: a repeated notification changes nothing and is logged once', function () {
    $payload = notification($this->booking);

    $this->postJson('/webhooks/midtrans', $payload)->assertJson(['outcome' => 'applied']);
    $paidAt = $this->booking->fresh()->paid_at;

    Carbon::setTestNow('2026-06-15 01:05:00');
    $this->postJson('/webhooks/midtrans', $payload)->assertOk()->assertJson(['outcome' => 'duplicate']);

    expect($this->booking->fresh()->paid_at->equalTo($paidAt))->toBeTrue()
        ->and(PaymentNotification::count())->toBe(1);
});

it('treats a different notification for an already paid booking as harmless', function () {
    $this->postJson('/webhooks/midtrans', notification($this->booking));
    $this->postJson('/webhooks/midtrans', notification($this->booking, override: ['transaction_id' => 'trx-2']))
        ->assertJson(['outcome' => 'ignored_already_paid']);

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Paid)
        ->and(PaymentNotification::count())->toBe(2);
});

it('ignores a late pending notification after settlement', function () {
    $this->postJson('/webhooks/midtrans', notification($this->booking));

    $this->postJson('/webhooks/midtrans', notification($this->booking, 'pending'))
        ->assertJson(['outcome' => 'pending_noted']);

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Paid);
});

it('ignores an expire notification that arrives after settlement', function () {
    $this->postJson('/webhooks/midtrans', notification($this->booking));

    $this->postJson('/webhooks/midtrans', notification($this->booking, 'expire'))
        ->assertJson(['outcome' => 'ignored_out_of_order']);

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Paid);
});

it('ignores a deny after a checked-in booking', function () {
    $this->booking->update(['status' => BookingStatus::CheckedIn]);

    $this->postJson('/webhooks/midtrans', notification($this->booking, 'deny'))
        ->assertJson(['outcome' => 'ignored_out_of_order']);

    expect($this->booking->fresh()->status)->toBe(BookingStatus::CheckedIn);
});

it('cancels an unpaid booking on deny, cancel or failure', function (string $status) {
    $this->postJson('/webhooks/midtrans', notification($this->booking, $status))
        ->assertJson(['outcome' => 'applied']);

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Cancelled);
})->with(['deny', 'cancel', 'failure']);

it('expires an unpaid booking on an expire notification', function () {
    $this->postJson('/webhooks/midtrans', notification($this->booking, 'expire'));

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Expired);
});

it('does not mark a booking paid when the amount differs from the deposit', function () {
    $payload = notification($this->booking, override: ['gross_amount' => '10000.00']);

    $this->postJson('/webhooks/midtrans', $payload)->assertJson(['outcome' => 'amount_mismatch']);

    expect($this->booking->fresh()->status)->toBe(BookingStatus::PendingPayment);
});

it('waits for fraud review before accepting a card capture', function () {
    $challenge = notification($this->booking, 'capture', ['fraud_status' => 'challenge', 'status_code' => '201']);
    $this->postJson('/webhooks/midtrans', $challenge)->assertJson(['outcome' => 'ignored_status']);
    expect($this->booking->fresh()->status)->toBe(BookingStatus::PendingPayment);

    $accepted = notification($this->booking, 'capture', ['status_code' => '200', 'transaction_id' => 'trx-9']);
    $this->postJson('/webhooks/midtrans', $accepted)->assertJson(['outcome' => 'applied']);
    expect($this->booking->fresh()->status)->toBe(BookingStatus::Paid);
});

it('acknowledges notifications for unknown orders so Midtrans stops retrying', function () {
    $ghost = Booking::factory()->make(['order_id' => 'ANT-UNKNOWN']);

    $this->postJson('/webhooks/midtrans', notification($ghost))
        ->assertOk()
        ->assertJson(['outcome' => 'unknown_order']);
});

it('revives a booking paid just after its hold expired when the slot is still free', function () {
    $this->booking->update(['status' => BookingStatus::Expired]);

    $this->postJson('/webhooks/midtrans', notification($this->booking))
        ->assertJson(['outcome' => 'late_payment_applied']);

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Paid);
});

it('leaves a late payment expired when the slot was taken meanwhile', function () {
    $this->booking->update(['status' => BookingStatus::Expired]);
    Booking::factory()->count(2)->create([
        'service_id' => $this->s->a->id,
        'starts_at' => '2026-06-15 03:00:00',
        'ends_at' => '2026-06-15 03:15:00',
        'status' => BookingStatus::Paid,
    ]);

    $this->postJson('/webhooks/midtrans', notification($this->booking))
        ->assertJson(['outcome' => 'late_payment_no_capacity']);

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Expired);
});

it('never stores the signature in the log', function () {
    $this->postJson('/webhooks/midtrans', notification($this->booking));

    expect(PaymentNotification::first()->payload)->not->toHaveKey('signature_key');
});

it('signs fake payments with the same scheme, through the same code path', function () {
    config(['antre.payments.driver' => 'fake']);

    $payload = app(FakePayment::class)->payload($this->booking);
    $this->postJson('/webhooks/midtrans', $payload)->assertOk()->assertJson(['outcome' => 'applied']);

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Paid);
});

it('refuses the fake driver in production', function () {
    config(['antre.payments.driver' => 'fake']);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(FakePayment::class)->send($this->booking))->toThrow(RuntimeException::class);
});

it('treats a payment after the hold ran out, before the sweep, like an expired booking', function () {
    Carbon::setTestNow('2026-06-15 01:20:00');
    expect($this->booking->fresh()->status)->toBe(BookingStatus::PendingPayment);

    Booking::factory()->count(2)->create([
        'service_id' => $this->s->a->id,
        'starts_at' => '2026-06-15 03:00:00',
        'ends_at' => '2026-06-15 03:15:00',
        'status' => BookingStatus::Paid,
    ]);

    $this->postJson('/webhooks/midtrans', notification($this->booking))
        ->assertJson(['outcome' => 'late_payment_no_capacity']);

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Expired);
});

it('still accepts a payment after the hold ran out when the slot has room', function () {
    Carbon::setTestNow('2026-06-15 01:20:00');

    $this->postJson('/webhooks/midtrans', notification($this->booking))
        ->assertJson(['outcome' => 'late_payment_applied']);

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Paid);
});
