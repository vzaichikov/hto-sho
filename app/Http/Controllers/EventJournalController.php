<?php

namespace App\Http\Controllers;

use App\HarnessRunType;
use App\Models\Event;
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
        $runs = $event->harnessRuns()
            ->when($selectedType, fn ($query) => $query->where('type', $selectedType))
            ->with(['entries' => function (Relation $query): void {
                $query->oldest('sequence');
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
