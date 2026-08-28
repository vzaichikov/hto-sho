<?php

namespace App\Http\Controllers;

use App\HarnessRunType;
use App\Models\Event;
use App\Models\HarnessRun;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EventJournalController extends Controller
{
    public function __invoke(Request $request, Event $event): View
    {
        Gate::authorize('view', $event);

        $selectedType = HarnessRunType::tryFrom($request->string('type')->toString());
        $runs = HarnessRun::query()
            ->visibleInJournalFor($event)
            ->when($selectedType, fn ($query) => $query->where('type', $selectedType))
            ->with(['entries' => function (Relation $query): void {
                $query
                    ->select([
                        'id',
                        'harness_run_id',
                        'sequence',
                        'kind',
                        'status',
                        'title',
                        'message',
                        'method',
                        'endpoint',
                        'status_code',
                        'duration_ms',
                        'created_at',
                    ])
                    ->selectRaw('request_payload IS NOT NULL AS has_request_payload')
                    ->selectRaw('response_payload IS NOT NULL AS has_response_payload')
                    ->selectRaw('metadata IS NOT NULL AS has_metadata')
                    ->selectRaw('LENGTH(request_payload) AS request_payload_bytes')
                    ->selectRaw('LENGTH(response_payload) AS response_payload_bytes')
                    ->selectRaw('LENGTH(metadata) AS metadata_bytes')
                    ->oldest('sequence');
            }])
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return view('events.journal', [
            'event' => $event,
            'runs' => $runs,
            'types' => HarnessRunType::cases(),
            'selectedType' => $selectedType,
        ]);
    }
}
