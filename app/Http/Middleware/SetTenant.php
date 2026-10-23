<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenant
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $businessId = $request->user()?->business_id;

        abort_if($businessId === null, 403, 'This account does not belong to a business.');

        $this->tenant->set($businessId);

        try {
            return $next($request);
        } finally {
            $this->tenant->set(null);
        }
    }
}
