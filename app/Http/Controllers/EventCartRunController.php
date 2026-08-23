<?php

namespace App\Http\Controllers;

use App\Actions\StartEventCartRunAction;
use App\CartRunMode;
use App\Exceptions\SilpoCartUnavailableException;
use App\Http\Requests\StartEventCartRunRequest;
use App\Models\Event;
use App\Models\EventCartRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

class EventCartRunController extends Controller
{
    public function store(
        StartEventCartRunRequest $request,
        Event $event,
        StartEventCartRunAction $start,
    ): JsonResponse {
        try {
            $run = $start->execute($event, CartRunMode::from($request->validated('mode')));
        } catch (SilpoCartUnavailableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->reason,
                'action_url' => $exception->reason === 'connection_missing'
                    ? route('mcp.oauth.silpo.connect')
                    : config('services.silpo_mcp.shop_url'),
            ], 409);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'message' => 'Гусь не зміг рушити до кошика. Спробуйте ще раз.',
            ], 503);
        }

        return response()->json([
            'run_url' => route('events.cart-runs.show', [$event, $run]),
        ], 202);
    }

    public function show(Request $request, Event $event, EventCartRun $cartRun): JsonResponse
    {
        Gate::authorize('view', $event);
        abort_unless($cartRun->event_id === $event->id, 404);

        $after = max($request->integer('after'), 0);
        $steps = $cartRun->steps()
            ->where('sequence', '>', $after)
            ->oldest('sequence')
            ->get()
            ->map(fn ($step): array => [
                'sequence' => $step->sequence,
                'kind' => $step->kind,
                'message' => $step->message,
                'created_at' => $step->created_at?->toISOString(),
            ])
            ->values();
        $needs = collect(data_get($cartRun->state, 'needs', []));
        $finishedNeeds = $needs->whereIn('status', ['selected', 'skipped'])->count();
        $progress = $cartRun->status->isTerminal()
            ? 100
            : min(92, 8 + (int) round(($finishedNeeds / max($needs->count(), 1)) * 80));

        return response()->json([
            'status' => $cartRun->status->value,
            'status_label' => $cartRun->status->label(),
            'mode_label' => $cartRun->mode->label(),
            'terminal' => $cartRun->status->isTerminal(),
            'progress' => $progress,
            'blocker' => $cartRun->blocker,
            'error' => $cartRun->error,
            'steps' => $steps,
            'last_sequence' => data_get($steps->last(), 'sequence', $after),
            'existing_items' => data_get($cartRun->cart_context, 'items', []),
            'staged_items' => $cartRun->staged_items ?? [],
            'warnings' => $cartRun->warnings ?? [],
            'estimated_total' => $cartRun->estimated_total,
            'actual_total' => $cartRun->actual_total,
            'validations' => data_get($cartRun->state, 'verified_cart.validations', data_get($cartRun->cart_context, 'validations', [])),
            'continue_url' => route('events.cart-runs.continue', [$event, $cartRun]),
        ]);
    }
}
