<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Payments\InvalidNotification;
use App\Payments\NotificationProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MidtransController extends Controller
{
    public function __invoke(Request $request, NotificationProcessor $processor): JsonResponse
    {
        try {
            $outcome = $processor->handle($request->json()->all() ?: $request->all());
        } catch (InvalidNotification $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode());
        }

        return response()->json(['status' => 'ok', 'outcome' => $outcome]);
    }
}
