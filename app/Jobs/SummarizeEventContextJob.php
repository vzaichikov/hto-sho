<?php

namespace App\Jobs;

use App\EventAnalysisStage;
use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use App\EventStatus;
use App\Models\Event;
use App\Models\EventSource;
use App\Services\ContextAnalysisService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

class SummarizeEventContextJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    public int $tries = 3;

    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $eventId,
        public readonly string $taskId,
    ) {
        $this->onQueue('ai-events');
    }

    public function uniqueId(): string
    {
        return $this->eventId.':'.$this->taskId;
    }

    public function handle(ContextAnalysisService $analysis): void
    {
        $event = Event::query()->find($this->eventId);

        if ($event === null || $event->analysis_task_id !== $this->taskId || ! $event->hasActiveAnalysis()) {
            return;
        }

        if ($this->mustWaitForQuietWindow($event) || $this->hasUnfinishedImages($event)) {
            $event->update([
                'analysis_stage' => $this->hasUnfinishedImages($event)
                    ? EventAnalysisStage::WaitingForImages
                    : EventAnalysisStage::WaitingForQuiet,
            ]);
            $this->release(5);

            return;
        }

        $sources = $event->sources()
            ->with('imageExtraction')
            ->oldest('created_at')
            ->oldest('id')
            ->get();

        $usableSources = $sources
            ->filter(fn ($source): bool => $source->status === EventSourceStatus::Processed
                && $source->inclusion !== EventSourceInclusion::Dismissed)
            ->values();
        $omittedSourceIds = $sources
            ->where('status', EventSourceStatus::Failed)
            ->pluck('id')
            ->values()
            ->all();

        if ($usableSources->isEmpty()) {
            $this->markFailed('Немає придатних джерел для підсумку. Додайте текст або зрозуміле зображення.');

            return;
        }

        $evidenceVersion = $event->evidence_version;
        $event->update([
            'analysis_stage' => EventAnalysisStage::Summarizing,
            'analysis_error' => null,
        ]);

        $evidence = $usableSources
            ->groupBy(fn ($source): string => (string) $source->upload_batch)
            ->map(function ($batchSources, string $uploadBatch): array {
                $batchSources = $batchSources
                    ->sortBy([
                        ['position', 'asc'],
                        ['id', 'asc'],
                    ])
                    ->values();
                $firstUploadedSource = $batchSources->sortBy('created_at')->first();

                return [
                    'upload_batch' => $uploadBatch,
                    'batch_uploaded_at' => $firstUploadedSource?->created_at?->toIso8601String(),
                    'sources' => $batchSources
                        ->map(fn ($source): array => $this->evidenceSource($source))
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $context = $analysis->summarizeEvent($evidence);
        $state = $context->state;
        $validSourceIds = $usableSources->pluck('id')->all();

        if (! $this->hasValidProvenance($state, $validSourceIds)) {
            throw new UnexpectedValueException('AI context contains unknown source IDs.');
        }

        $state['source_ids'] = $validSourceIds;
        $state['omitted_source_ids'] = $omittedSourceIds;

        if ($omittedSourceIds !== []) {
            $state['warnings'][] = [
                'message' => 'Частину зображень не вдалося обробити; підсумок є частковим.',
                'source_ids' => $omittedSourceIds,
            ];
        }

        $shouldRetry = DB::transaction(function () use ($event, $evidenceVersion, $state, $omittedSourceIds): bool {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);

            if ($lockedEvent->analysis_task_id !== $this->taskId) {
                return false;
            }

            if (
                $lockedEvent->evidence_version !== $evidenceVersion
                || $this->hasUnfinishedImages($lockedEvent)
                || $this->mustWaitForQuietWindow($lockedEvent)
            ) {
                $lockedEvent->update([
                    'analysis_stage' => $this->hasUnfinishedImages($lockedEvent)
                        ? EventAnalysisStage::WaitingForImages
                        : EventAnalysisStage::WaitingForQuiet,
                ]);

                return true;
            }

            $lockedEvent->update([
                'state' => $state,
                'state_version' => $lockedEvent->state_version + 1,
                'state_evidence_version' => $evidenceVersion,
                'status' => EventStatus::Ready,
                'analysis_stage' => $omittedSourceIds === []
                    ? EventAnalysisStage::Completed
                    : EventAnalysisStage::CompletedWithWarnings,
                'analysis_error' => null,
                'analysis_finished_at' => now(),
            ]);

            return false;
        });

        if ($shouldRetry) {
            $this->release(5);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed(mb_substr(
            $exception?->getMessage() ?? 'Не вдалося скласти підсумок.',
            0,
            2000,
        ));
    }

    private function hasUnfinishedImages(Event $event): bool
    {
        return $event->sources()
            ->where('type', EventSourceType::Image)
            ->whereIn('status', [EventSourceStatus::Pending, EventSourceStatus::Processing])
            ->exists();
    }

    private function mustWaitForQuietWindow(Event $event): bool
    {
        return $event->last_source_at !== null
            && $event->last_source_at->copy()->addSeconds(5)->isFuture();
    }

    /** @return array<string, mixed> */
    private function evidenceSource(EventSource $source): array
    {
        $evidence = [
            'source_id' => $source->id,
            'kind' => $source->type->value,
            'uploaded_at' => $source->created_at?->toIso8601String(),
            'position' => $source->position,
        ];

        if ($source->type === EventSourceType::Text) {
            $evidence['text'] = $source->text;

            return $evidence;
        }

        return [
            ...$evidence,
            'classification' => $source->imageExtraction?->classification?->value,
            'ocr_text' => $source->imageExtraction?->ocr_text,
            'message_timeline' => $source->imageExtraction?->message_timeline,
            'source_summary' => $source->imageExtraction?->source_summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<int, int>  $validSourceIds
     */
    private function hasValidProvenance(array $state, array $validSourceIds): bool
    {
        $referenced = collect($state['source_ids'] ?? []);

        foreach (['participants', 'restrictions', 'agreements', 'warnings', 'unresolved_questions'] as $section) {
            foreach ($state[$section] ?? [] as $item) {
                $referenced = $referenced->merge($item['source_ids'] ?? []);
            }
        }

        return $referenced
            ->every(fn (mixed $sourceId): bool => in_array($sourceId, $validSourceIds, true));
    }

    private function markFailed(string $message): void
    {
        Event::query()
            ->whereKey($this->eventId)
            ->where('analysis_task_id', $this->taskId)
            ->update([
                'status' => EventStatus::Failed,
                'analysis_stage' => EventAnalysisStage::Failed,
                'analysis_error' => $message,
                'analysis_finished_at' => now(),
            ]);
    }
}
