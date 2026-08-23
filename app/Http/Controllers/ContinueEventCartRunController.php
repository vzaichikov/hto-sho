<?php

namespace App\Http\Controllers;

use App\Actions\ContinueEventCartRunAction;
use App\Http\Requests\ContinueEventCartRunRequest;
use App\Models\Event;
use App\Models\EventCartRun;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ContinueEventCartRunController extends Controller
{
    public function __invoke(
        ContinueEventCartRunRequest $request,
        Event $event,
        EventCartRun $cartRun,
        ContinueEventCartRunAction $continue,
    ): JsonResponse {
        abort_unless($cartRun->event_id === $event->id, 404);

        try {
            $continue->execute($cartRun, $request->validated('answer'));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'run_url' => route('events.cart-runs.show', [$event, $cartRun]),
        ], 202);
    }
}
