<?php

namespace App\Jobs;

use App\EventAnalysisStage;
use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use App\EventStatus;
use App\HarnessEntryKind;
use App\HarnessRunStatus;
use App\HarnessRunType;
use App\Models\Event;
use App\Models\EventContextVersion;
use App\Models\EventSource;
use App\Models\HarnessRun;
use App\PlanGenerationStatus;
use App\Services\ContextAnalysisService;
use App\Services\HarnessRecorder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function handle(ContextAnalysisService $analysis, HarnessRecorder $harnessRecorder): void
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

        if ($usableSources->isEmpty() && blank($event->description)) {
            $this->markFailed('Гусю бракує і задуму, і придатних джерел. Додайте короткий опис, текст або зрозуміле зображення.');

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

        $harnessRun = $harnessRecorder->start(
            event: $event,
            type: HarnessRunType::ContextSynthesis,
            correlationId: $this->taskId,
            metadata: [
                'evidence_version' => $evidenceVersion,
                'source_count' => $usableSources->count(),
            ],
        );
        $harnessRecorder->append(
            run: $harnessRun,
            kind: HarnessEntryKind::Action,
            title: 'Гусь зібрав джерела для нового контексту',
            message: sprintf('Використано джерел: %d; пропущено через помилки: %d.', $usableSources->count(), count($omittedSourceIds)),
        );

        $usableSources
            ->where('origin', 'question_answer')
            ->each(function (EventSource $source) use ($harnessRecorder, $harnessRun): void {
                if ($harnessRun->entries()
                    ->where('kind', HarnessEntryKind::Answer)
                    ->where('metadata->source_id', $source->id)
                    ->exists()) {
                    return;
                }

                $harnessRecorder->append(
                    run: $harnessRun,
                    kind: HarnessEntryKind::Answer,
                    title: (string) data_get($source->metadata, 'question', 'Відповідь організатора'),
                    message: (string) data_get($source->metadata, 'answer', $source->text),
                    metadata: ['source_id' => $source->id, 'question_key' => data_get($source->metadata, 'question_key')],
                );
            });

        $context = $analysis->summarizeEvent([
            'title' => $event->title,
            'description' => $event->description,
            'alcohol_planned' => $event->alcohol_planned,
            'people_count' => $event->people_count,
            'budget_amount' => $event->budget_amount,
            'currency' => $event->currency,
        ], $evidence, $this->questionLedger($event, $usableSources), $harnessRun);
        $state = $this->normalizeOrganizerConfirmedAlcohol($context->state, $event->alcohol_planned);
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

        $result = DB::transaction(function () use ($event, $evidenceVersion, $state, $omittedSourceIds): array {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);

            if ($lockedEvent->analysis_task_id !== $this->taskId) {
                return ['retry' => false, 'state_version' => null];
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

                return ['retry' => true, 'state_version' => null];
            }

            $stateVersion = $lockedEvent->state_version + 1;

            EventContextVersion::query()->firstOrCreate([
                'event_id' => $lockedEvent->id,
                'state_version' => $stateVersion,
            ], [
                'evidence_version' => $evidenceVersion,
                'state' => $state,
            ]);

            $lockedEvent->update([
                'state' => $state,
                'state_version' => $stateVersion,
                'state_evidence_version' => $evidenceVersion,
                'status' => EventStatus::Ready,
                'analysis_stage' => $omittedSourceIds === []
                    ? EventAnalysisStage::Completed
                    : EventAnalysisStage::CompletedWithWarnings,
                'analysis_error' => null,
                'analysis_finished_at' => now(),
                'plan_generation_status' => PlanGenerationStatus::Pending,
                'plan_generation_error' => null,
            ]);

            return ['retry' => false, 'state_version' => $stateVersion];
        });

        if ($result['retry']) {
            $harnessRecorder->append(
                run: $harnessRun,
                kind: HarnessEntryKind::Action,
                title: 'Зʼявилися нові дані — запуск буде повторено',
            );
            $this->release(5);

            return;
        }

        if (is_int($result['state_version'])) {
            foreach ($state['unresolved_questions'] ?? [] as $question) {
                $harnessRecorder->append(
                    run: $harnessRun,
                    kind: HarnessEntryKind::Question,
                    title: (string) ($question['question'] ?? 'Питання до організатора'),
                    message: (string) ($question['impact'] ?? ''),
                    metadata: [
                        'question_key' => $question['key'] ?? null,
                        'blocking' => $question['blocking'] ?? false,
                    ],
                );
            }
            $harnessRecorder->append(
                run: $harnessRun,
                kind: HarnessEntryKind::Action,
                title: 'Новий контекст збережено',
                message: 'Версія стану: '.$result['state_version'],
            );
            $harnessRecorder->finish($harnessRun);
            BuildEventShoppingPlanJob::dispatch($event->id, $result['state_version'])->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        HarnessRun::query()
            ->where('event_id', $this->eventId)
            ->where('type', HarnessRunType::ContextSynthesis)
            ->where('correlation_id', $this->taskId)
            ->update([
                'status' => HarnessRunStatus::Failed,
                'error' => mb_substr($exception?->getMessage() ?? 'Не вдалося скласти підсумок.', 0, 2000),
                'finished_at' => now(),
            ]);

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

    /**
     * @return array{open: array<int, array{key: string, question: string}>, answered: array<int, array{key: string, question: string, answer: string, source_id: int}>}
     */
    private function questionLedger(Event $event, Collection $usableSources): array
    {
        $answerSources = $usableSources->where('origin', 'question_answer');
        $answered = $answerSources
            ->map(function (EventSource $source): ?array {
                $key = data_get($source->metadata, 'question_key');
                $question = data_get($source->metadata, 'question');
                $answer = data_get($source->metadata, 'answer');

                if (! is_string($key) || ! is_string($question) || ! is_string($answer)) {
                    return null;
                }

                return [
                    'key' => $key,
                    'question' => $question,
                    'answer' => $answer,
                    'source_id' => $source->id,
                ];
            })
            ->filter()
            ->values();
        $resolvedAliases = collect($event->state['unresolved_questions'] ?? [])
            ->filter(fn (mixed $question): bool => is_array($question)
                && is_string($question['key'] ?? null)
                && is_string($question['question'] ?? null))
            ->map(function (array $question) use ($answerSources): ?array {
                $sourceIds = collect($question['source_ids'] ?? [])->filter(fn (mixed $id): bool => is_int($id));
                $optionLabels = collect($question['options'] ?? [])
                    ->pluck('label')
                    ->filter(fn (mixed $label): bool => is_string($label))
                    ->mapWithKeys(fn (string $label): array => [Str::lower(Str::squish($label)) => $label]);
                $matchingSource = $answerSources
                    ->whereIn('id', $sourceIds)
                    ->first(function (EventSource $source) use ($optionLabels): bool {
                        $answer = data_get($source->metadata, 'answer');

                        return is_string($answer)
                            && $optionLabels->has(Str::lower(Str::squish($answer)));
                    });

                if (! $matchingSource instanceof EventSource) {
                    return null;
                }

                return [
                    'key' => $question['key'],
                    'question' => $question['question'],
                    'answer' => (string) data_get($matchingSource->metadata, 'answer'),
                    'source_id' => $matchingSource->id,
                ];
            })
            ->filter();
        $answered = $answered
            ->merge($resolvedAliases)
            ->unique('key')
            ->values();
        $answeredKeys = $answered->pluck('key');
        $open = collect($event->state['unresolved_questions'] ?? [])
            ->filter(fn (mixed $question): bool => is_array($question)
                && is_string($question['key'] ?? null)
                && is_string($question['question'] ?? null))
            ->reject(fn (array $question): bool => $answeredKeys->containsStrict($question['key']))
            ->map(fn (array $question): array => [
                'key' => $question['key'],
                'question' => $question['question'],
            ])
            ->values();

        return [
            'open' => $open->all(),
            'answered' => $answered->all(),
        ];
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
            'origin' => $source->origin,
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

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalizeOrganizerConfirmedAlcohol(array $state, bool $alcoholPlanned): array
    {
        if (! $alcoholPlanned) {
            return $state;
        }

        $state['unresolved_questions'] = collect($state['unresolved_questions'] ?? [])
            ->reject(function (array $question): bool {
                $text = Str::lower(Str::squish(
                    ($question['question'] ?? '').' '.($question['impact'] ?? ''),
                ));

                if (! str_contains($text, 'алкогол')) {
                    return false;
                }

                return preg_match('/(який|яке|які|скільки|кільк|обсяг|сорт|вид|міцн|літр|бан|пляш|хто)/u', $text) !== 1;
            })
            ->values()
            ->all();

        $state['warnings'] = collect($state['warnings'] ?? [])
            ->reject(function (array $warning): bool {
                $message = Str::lower($warning['message'] ?? '');

                return str_contains($message, 'алкогол')
                    && (str_contains($message, 'не підтвердж') || str_contains($message, 'не виріш'));
            })
            ->values()
            ->all();

        return $state;
    }
}
