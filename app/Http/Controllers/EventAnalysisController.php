<?php

namespace App\Http\Controllers;

use App\Actions\StartEventAnalysisAction;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EventAnalysisController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        Request $request,
        Event $event,
        StartEventAnalysisAction $startAnalysis,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('update', $event);
        $event = $startAnalysis->execute($event);

        if ($request->expectsJson()) {
            return response()->json([
                'task_id' => $event->analysis_task_id,
                'stage' => $event->analysis_stage?->value,
                'message' => $event->analysis_stage?->message(
                    $event->analysis_task_id,
                    $event->analysis_started_at,
                ),
                'started_at' => $event->analysis_started_at?->toISOString(),
            ], 202);
        }

        return back()->with('success', 'Гусь узявся розгрібати контекст. Можете додавати ще — він дочекається.');
    }
}
