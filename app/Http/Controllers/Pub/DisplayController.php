<?php

namespace App\Http\Controllers\Pub;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Queue\DisplaySnapshot;
use Inertia\Inertia;
use Inertia\Response;

class DisplayController extends Controller
{
    public function __invoke(Branch $branch, DisplaySnapshot $snapshots): Response
    {
        return Inertia::render('display/show', [
            'branch' => ['id' => $branch->id, 'name' => $branch->name, 'slug' => $branch->slug],
            'snapshot' => $snapshots->for($branch),
        ]);
    }
}
