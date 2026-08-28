<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\HarnessEntry;
use App\Models\HarnessRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class EventJournalEntryPayloadController extends Controller
{
    public function __invoke(
        Event $event,
        HarnessRun $harnessRun,
        HarnessEntry $harnessEntry,
    ): JsonResponse {
        Gate::authorize('view', $event);

        abort_unless(
            HarnessRun::query()
                ->visibleInJournalFor($event)
                ->whereKey($harnessRun->getKey())
                ->exists(),
            404,
        );
        abort_unless($harnessEntry->harness_run_id === $harnessRun->id, 404);

        return response()->json([
            'request_payload' => $harnessEntry->request_payload,
            'response_payload' => $harnessEntry->response_payload,
            'metadata' => $harnessEntry->metadata,
        ]);
    }
}
