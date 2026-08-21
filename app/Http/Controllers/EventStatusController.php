<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EventStatusController extends Controller
{
    public function __invoke(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('view', $event);

        $event->loadCount([
            'sources',
            'sources as pending_sources_count' => fn ($query) => $query->where('status', 'pending'),
        ]);

        return response()->json([
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
            'state_version' => $event->state_version,
            'plan_current' => $event->isPlanCurrent(),
            'cart_current' => $event->isCartCurrent(),
            'sources_count' => $event->sources_count,
            'pending_sources_count' => $event->pending_sources_count,
            'updated_at' => $event->updated_at?->toISOString(),
        ]);
    }
}
