<?php

namespace App\Http\Controllers\Pub;

use App\Broadcasting\AblyTokens;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a browser gets its Ably token. Anyone may listen on the public channels, and staff also
 * get the private channels of the branches they work at. Nobody may publish: only the server does.
 */
class AblyTokenController extends Controller
{
    public function __invoke(Request $request, AblyTokens $tokens): JsonResponse
    {
        abort_unless(config('broadcasting.default') === 'ably', 404);

        $capability = ['public:*' => ['subscribe']];

        // Ably checks the token and not routes/channels.php, so this is the same test as that callback.
        if ($user = $request->user()) {
            foreach (Branch::withoutGlobalScopes()->where('business_id', $user->business_id)->get() as $branch) {
                if ($user->can('operate', $branch)) {
                    $capability["private:staff.branch.{$branch->id}"] = ['subscribe'];
                }
            }
        }

        return response()->json($tokens->issue($capability))->header('Cache-Control', 'no-store');
    }
}
