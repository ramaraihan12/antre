<?php

namespace App\Http\Controllers\Pub;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Business;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        $businesses = Business::query()
            ->with(['branches' => fn ($q) => $q->withoutGlobalScopes()->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Business $business) => [
                'name' => $business->name,
                'branches' => $business->branches->map(fn (Branch $branch) => [
                    'name' => $branch->name,
                    'slug' => $branch->slug,
                    'city' => $branch->city,
                ]),
            ]);

        return Inertia::render('welcome', ['businesses' => $businesses]);
    }
}
