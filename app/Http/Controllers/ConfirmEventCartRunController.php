<?php

namespace App\Http\Controllers;

use App\Actions\ConfirmEventCartRunAction;
use App\Models\Event;
use App\Models\EventCartRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

class ConfirmEventCartRunController extends Controller
{
    public function __invoke(
        Event $event,
        EventCartRun $cartRun,
        ConfirmEventCartRunAction $confirm,
    ): JsonResponse {
        Gate::authorize('view', $event);
        abort_unless($cartRun->event_id === $event->id, 404);

        try {
            $confirm->execute($cartRun);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'message' => 'Гусь не зміг безпечно перевірити кошик перед записом. Спробуйте ще раз.',
            ], 503);
        }

        return response()->json([
            'run_url' => route('events.cart-runs.show', [$event, $cartRun]),
        ], 202);
    }
}
