<?php

namespace App\Http\Controllers;

use App\EventAnalysisStage;
use App\EventSourceStatus;
use App\EventSourceType;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EventStatusController extends Controller
{
    public function __invoke(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('view', $event);

        $event->load([
            'sources' => fn ($query) => $query
                ->with('imageExtraction')
                ->oldest('created_at')
                ->oldest('id'),
        ]);

        $counts = collect(EventSourceStatus::cases())
            ->mapWithKeys(fn (EventSourceStatus $status): array => [
                $status->value => $event->sources->where('status', $status)->count(),
            ]);
        $totalImages = $event->sources->where('type', EventSourceType::Image)->count();
        $terminalImages = $event->sources
            ->where('type', EventSourceType::Image)
            ->whereIn('status', [EventSourceStatus::Processed, EventSourceStatus::Failed])
            ->count();
        $stage = $event->analysis_stage;
        $progress = $stage?->progress();

        if ($stage === EventAnalysisStage::WaitingForImages) {
            $progress = $totalImages === 0
                ? 70
                : 15 + (int) round(($terminalImages / $totalImages) * 55);
        }

        $sources = $event->sources->map(fn ($source): array => [
            'id' => $source->id,
            'status' => $source->status->value,
            'status_label' => $source->status->label(),
            'inclusion' => $source->inclusion->value,
            'classification' => $source->imageExtraction?->classification?->value,
            'classification_label' => $source->imageExtraction?->classification?->label(),
            'cached' => $source->used_cached_extraction,
            'progress' => match ($source->status) {
                EventSourceStatus::Pending => 12,
                EventSourceStatus::Processing => 62,
                EventSourceStatus::Processed, EventSourceStatus::Failed => 100,
            },
            'message' => match ($source->status) {
                EventSourceStatus::Pending => 'Ще одна картинка? Ну звісно. Давайте сюди.',
                EventSourceStatus::Processing => 'Гусь Шо дивиться на вашу картинку. Пильно. Трохи осудливо.',
                EventSourceStatus::Processed => $source->used_cached_extraction
                    ? 'Це вже бачили. OCR вдруге не ганяли.'
                    : 'Розібрано. І навіть без драматичної паузи.',
                EventSourceStatus::Failed => 'Картинка відбилася від Гуся. Можна спробувати ще раз.',
            },
            'updated_at' => $source->updated_at?->toISOString(),
        ])->values();

        $sourceRevision = $event->sources->max(fn ($source): ?string => $source->updated_at?->toISOString());

        return response()->json([
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
            'state_version' => $event->state_version,
            'evidence_version' => $event->evidence_version,
            'state_evidence_version' => $event->state_evidence_version,
            'has_unanalyzed_changes' => $event->hasUnanalyzedChanges(),
            'plan_current' => $event->isPlanCurrent(),
            'cart_current' => $event->isCartCurrent(),
            'sources_count' => $event->sources->count(),
            'source_counts' => $counts,
            'included_sources_count' => $event->sources->filter->isIncluded()->count(),
            'dismissed_sources_count' => $event->sources->reject->isIncluded()->count(),
            'sources' => $sources,
            'full_task' => $event->analysis_task_id === null ? null : [
                'id' => $event->analysis_task_id,
                'stage' => $stage?->value,
                'progress' => $progress,
                'message' => $stage?->message(),
                'started_at' => $event->analysis_started_at?->toISOString(),
                'finished_at' => $event->analysis_finished_at?->toISOString(),
                'error' => $event->analysis_error,
            ],
            'revision' => hash('sha256', implode('|', [
                $event->updated_at?->toISOString(),
                $sourceRevision,
                $event->state_version,
                $event->evidence_version,
            ])),
            'updated_at' => $event->updated_at?->toISOString(),
        ]);
    }
}
