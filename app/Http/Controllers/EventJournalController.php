<?php

namespace App\Http\Controllers;

use App\HarnessRunType;
use App\Models\Event;
use App\Models\HarnessRun;
use Illuminate\Database\Eloquent\Builder;
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
        $imageCorrelationIds = $event->sources()
            ->whereNotNull('image_extraction_id')
            ->pluck('image_extraction_id')
            ->map(fn (int $imageExtractionId): string => 'image-'.$imageExtractionId)
            ->all();
        $runs = HarnessRun::query()
            ->where(function (Builder $query) use ($event, $imageCorrelationIds): void {
                $query->whereBelongsTo($event);

                if ($imageCorrelationIds !== []) {
                    $query->orWhere(function (Builder $imageQuery) use ($imageCorrelationIds): void {
                        $imageQuery
                            ->where('type', HarnessRunType::ImageExtraction)
                            ->whereIn('correlation_id', $imageCorrelationIds);
                    });
                }
            })
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
