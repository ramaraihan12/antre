<?php

use App\Http\Middleware\ExpireLapsedBookings;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LockDemoAccounts;
use App\Http\Middleware\SetTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => SetTenant::class,
            'demo.locked' => LockDemoAccounts::class,
            'expire-holds' => ExpireLapsedBookings::class,
        ]);

        // The tenant must be set before route model binding runs, or bindings would ignore it.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetTenant::class);

        // Same reason: the booking a page binds to must already show that its hold ran out.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ExpireLapsedBookings::class);

        // Midtrans calls this from outside. The signature check replaces the CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/midtrans']);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
