<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/*
 * The walk-in limit is 30 a minute per address. A load test can lift it with
 * LOADTEST_LIFT_WALKIN_LIMIT, and production must never honour that.
 */
beforeEach(function () {
    $this->s = scenario();
    $this->take = fn () => $this->post("/q/{$this->s->branch->slug}/tickets", ['service_id' => $this->s->a->id]);
});

it('stops the 31st walk-in of a minute from one address', function () {
    foreach (range(1, 30) as $i) {
        ($this->take)()->assertRedirect();
    }

    ($this->take)()->assertTooManyRequests();
});

it('lifts the walk-in limit when the load test switch is on outside production', function () {
    config(['antre.loadtest.lift_walkin_limit' => true]);

    foreach (range(1, 35) as $i) {
        ($this->take)()->assertRedirect();
    }
});

it('ignores the load test switch in production', function () {
    config(['antre.loadtest.lift_walkin_limit' => true]);
    app()->detectEnvironment(fn () => 'production');
    // CSRF checks are skipped only while APP_ENV is testing.
    $this->withoutMiddleware(ValidateCsrfToken::class);

    foreach (range(1, 30) as $i) {
        ($this->take)()->assertRedirect();
    }

    ($this->take)()->assertTooManyRequests();
});

it('ignores the load test switch in demo mode', function () {
    config([
        'antre.loadtest.lift_walkin_limit' => true,
        'antre.demo.enabled' => true,
        // Out of the way, so the 429 below comes from the walk-in limit and not from public-write.
        'antre.demo.writes_per_minute' => 1000,
        'antre.demo.writes_per_hour' => 1000,
        'antre.demo.global_writes_per_hour' => 1000,
    ]);

    foreach (range(1, 30) as $i) {
        ($this->take)()->assertRedirect();
    }

    ($this->take)()->assertTooManyRequests();
});

it('keeps the switch off unless the environment variable is set', function () {
    expect(config('antre.loadtest.lift_walkin_limit'))->toBeFalse();
});
