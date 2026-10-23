<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Stats\BranchStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, BranchStats $stats): Response|RedirectResponse
    {
        $user = $request->user();

        // Staff work at a counter. Only owners get the numbers.
        if (! $user->isOwner()) {
            return redirect()->route('console.index');
        }

        $branches = Branch::query()->orderBy('name')->get();
        $selected = $branches->firstWhere('id', $request->integer('branch')) ?? $branches->first();

        if ($selected !== null) {
            $this->authorize('viewDashboard', $selected);
        }

        return Inertia::render('dashboard', [
            'branches' => $branches->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name]),
            'branch' => $selected ? ['id' => $selected->id, 'name' => $selected->name] : null,
            'stats' => $selected ? $stats->today($selected) : null,
        ]);
    }
}
