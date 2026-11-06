<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimits();

        if (config('antre.demo.enabled')) {
            // Without the container the handler stores no address or user agent with the session,
            // and strangers' details have no reason to sit in the demo database.
            Session::extend('database', fn (Application $app) => new DatabaseSessionHandler(
                $app->make('db')->connection(config('session.connection')),
                config('session.table'),
                config('session.lifetime'),
            ));
        }
    }

    private function configureRateLimits(): void
    {
        // Sits on top of the limits a route already has, and does nothing unless this is the demo.
        // The per-visitor limits come first: a request they stop does not count towards the
        // shared cap, so one visitor cannot use it up for everyone.
        RateLimiter::for('public-write', function (Request $request) {
            if (! config('antre.demo.enabled')) {
                return Limit::none();
            }

            return [
                Limit::perMinute(config('antre.demo.writes_per_minute'))->by('minute:'.$request->ip()),
                Limit::perHour(config('antre.demo.writes_per_hour'))->by('hour:'.$request->ip()),
                Limit::perHour(config('antre.demo.global_writes_per_hour'))->by('everyone'),
            ];
        });

        RateLimiter::for('ably-token', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }
}
