<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Everyone shares the logins on the public demo, so one visitor must not be able to change them. */
class LockDemoAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if((bool) config('antre.demo.enabled'), 403, 'Akun demo dipakai bersama, jadi tidak bisa diubah.');

        return $next($request);
    }
}
