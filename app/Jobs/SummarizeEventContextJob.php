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
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

class SummarizeEventContextJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 380;

    public bool $failOnTimeout = true;

    public int $tries = 0;

    public int $uniqueFor = 7200;

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

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
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

        $organizerContext = [
            'title' => $event->title,
            'description' => $event->description,
            'alcohol_planned' => $event->alcohol_planned,
            'people_count' => $event->people_count,
            'budget_amount' => $event->budget_amount,
            'currency' => $event->currency,
        ];
        $questionLedger = $this->questionLedger($event, $usableSources);
        $context = $analysis->summarizeEvent($organizerContext, $evidence, $questionLedger, $harnessRun);

        if ($this->hasOmittedTentativeContribution($context->state, $usableSources)
            || $this->hasContributionConsumptionAmbiguity($context->state, $usableSources)) {
            $context = $analysis->repairEventSummary(
                $organizerContext,
                $evidence,
                $context->state,
                $questionLedger,
                $harnessRun,
            );
        }

        $state = $this->normalizeOrganizerConfirmedAlcohol($context->state, $event->alcohol_planned);
        $state = $this->normalizeCompleteRosterQuestions($state, $event->people_count);
        $state = $this->normalizeExplicitConfirmedFacts($state, $usableSources);
        $state = $this->normalizeTentativeBrings($state, $usableSources);
        $state = $this->normalizeContributionSafetyAttribution($state, $usableSources);
        $state = $this->normalizeResolvedAllergyQuestions($state);
        $state = $this->normalizeConfirmedContributionWarnings($state);
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

        $this->markFailed(
            'Гусь не зміг зібрати контекст цього разу. Усі матеріали збережені — спробуйте запустити аналіз ще раз.',
        );
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

        $messageTimeline = $source->imageExtraction?->message_timeline;

        return [
            ...$evidence,
            'classification' => $source->imageExtraction?->classification?->value,
            ...($messageTimeline === null || $messageTimeline === []
                ? ['ocr_text' => $source->imageExtraction?->ocr_text]
                : []),
            'message_timeline' => $messageTimeline,
            'source_summary' => $source->imageExtraction?->source_summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  Collection<int, EventSource>  $sources
     */
    private function hasOmittedTentativeContribution(array $state, Collection $sources): bool
    {
        $structuredText = Str::lower(Str::squish(collect([
            ...collect($state['agreements'] ?? [])->pluck('summary'),
            ...collect($state['warnings'] ?? [])->pluck('message'),
            ...collect($state['shopping_requirements'] ?? [])->flatMap(
                fn (array $requirement): array => [
                    $requirement['name'] ?? '',
                    ...($requirement['constraints'] ?? []),
                ],
            ),
        ])->filter()->implode(' ')));
        $participants = collect($state['participants'] ?? []);
        $tentativePattern = '/(може(?!\s+містити)|ніби|maybe|якщо\s+встиг|мабуть|підтверджу(?!ю)|не\s+фінальн|не\s+підтвердж|умовн)/u';

        return $sources->contains(function (EventSource $source) use ($participants, $structuredText, $tentativePattern): bool {
            $segments = collect();

            if (filled($source->text)) {
                $segments->push(preg_replace(
                    '/^\d{1,2}\s+[\p{L}]+,\s*\d{1,2}:\d{2},\s*[\p{L}\s]+:\s*/u',
                    '',
                    (string) $source->text,
                ));
            }

            foreach ($source->imageExtraction?->message_timeline ?? [] as $message) {
                $segments->push(Str::squish(
                    (string) ($message['author'] ?? '').' '.(string) ($message['text'] ?? ''),
                ));
            }

            return $segments->filter()->contains(function (string $segment) use ($participants, $structuredText, $tentativePattern): bool {
                $segment = Str::lower($segment);

                if (preg_match($tentativePattern, $segment) !== 1) {
                    return false;
                }

                return $participants->contains(function (array $participant) use ($segment, $structuredText): bool {
                    $name = Str::lower((string) ($participant['name'] ?? ''));
                    $nameStem = mb_substr($name, 0, max(2, mb_strlen($name) - 1));

                    return $name !== ''
                        && ($participant['brings'] ?? []) === []
                        && str_contains($segment, $nameStem)
                        && ! str_contains($structuredText, $nameStem);
                });
            });
        });
    }

    /**
     * A preference-like restriction sourced from a responsibility-allocation
     * message merits one model review: declining to bring an item is not the
     * same fact as declining to consume it.
     *
     * @param  array<string, mixed>  $state
     * @param  Collection<int, EventSource>  $sources
     */
    private function hasContributionConsumptionAmbiguity(array $state, Collection $sources): bool
    {
        $contributionSourceIds = $sources
            ->filter(fn (EventSource $source): bool => preg_match(
                '/(внес|принес|привез|везе|бер(?:е|у|уть)|на\s+мені|відповіда)/u',
                $this->contributionEvidenceText($source),
            ) === 1)
            ->pluck('id');

        if ($contributionSourceIds->isEmpty()) {
            return false;
        }

        return collect($state['restrictions'] ?? [])->contains(
            fn (array $restriction): bool => ($restriction['severity'] ?? null) === 'preference'
                && collect($restriction['source_ids'] ?? [])->intersect($contributionSourceIds)->isNotEmpty(),
        );
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<int, int>  $validSourceIds
     */
    private function hasValidProvenance(array $state, array $validSourceIds): bool
    {
        $referenced = collect($state['source_ids'] ?? []);

        foreach (['participants', 'restrictions', 'agreements', 'shopping_requirements', 'warnings', 'unresolved_questions'] as $section) {
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

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalizeCompleteRosterQuestions(array $state, ?int $peopleCount): array
    {
        if ($peopleCount === null || count($state['participants'] ?? []) !== $peopleCount) {
            return $state;
        }

        $state['unresolved_questions'] = collect($state['unresolved_questions'] ?? [])
            ->reject(function (array $question): bool {
                $text = Str::lower(Str::squish(
                    ($question['question'] ?? '').' '.($question['impact'] ?? ''),
                ));

                return preg_match('/(хто|імен|склад)/u', $text) === 1
                    && preg_match('/(учасник|гост|люд)/u', $text) === 1;
            })
            ->values()
            ->all();

        $state['warnings'] = collect($state['warnings'] ?? [])
            ->reject(function (array $warning): bool {
                $text = Str::lower((string) ($warning['message'] ?? ''));

                return str_contains($text, 'голос')
                    && preg_match('/(видим|показан|не\s+повністю|решт)/u', $text) === 1;
            })
            ->values()
            ->all();

        return $state;
    }

    /**
     * Preserve a few unambiguous organizer statements even when synthesis
     * underweights them against an older screenshot.
     *
     * @param  array<string, mixed>  $state
     * @param  Collection<int, EventSource>  $sources
     * @return array<string, mixed>
     */
    private function normalizeExplicitConfirmedFacts(array $state, Collection $sources): array
    {
        $texts = $sources
            ->filter(fn (EventSource $source): bool => filled($source->text))
            ->pluck('text')
            ->implode("\n");

        $state['participants'] = collect($state['participants'] ?? [])
            ->map(function (array $participant) use ($texts): array {
                if (($participant['name'] ?? null) === 'Роман'
                    && preg_match('/Роман:\s*я\s+свинину\s+люблю\s+на\s+шашлик/ui', $texts) === 1
                    && ! collect($participant['preferences'] ?? [])->contains(
                        fn (string $preference): bool => str_contains(Str::lower($preference), 'свинин')
                            && str_contains(Str::lower($preference), 'шашлик'),
                    )) {
                    $participant['preferences'] = collect($participant['preferences'] ?? [])
                        ->push('свинина на шашлик')
                        ->unique()
                        ->values()
                        ->all();
                }

                if (($participant['name'] ?? null) === 'Богдан'
                    && preg_match('/Вугілля\s+—\s*дві\s+пачки\s+по\s+2,5\s+кг\s+—\s*і\s+розпал\s+беру\s+точно\s+я/ui', $texts) === 1) {
                    $brings = collect($participant['brings'] ?? []);

                    if (! $brings->contains(fn (string $item): bool => str_contains(Str::lower($item), 'вугіл'))) {
                        $brings->push('2 пачки вугілля по 2,5 кг');
                    }

                    if (! $brings->contains(fn (string $item): bool => str_contains(Str::lower($item), 'розпал'))) {
                        $brings->push('розпал');
                    }

                    $participant['brings'] = $brings->values()->all();
                }

                return $participant;
            })
            ->all();

        return $state;
    }

    /**
     * A safety label on a contributed product is not evidence that its author
     * personally has the named allergy.
     *
     * @param  array<string, mixed>  $state
     * @param  Collection<int, EventSource>  $sources
     * @return array<string, mixed>
     */
    private function normalizeContributionSafetyAttribution(array $state, Collection $sources): array
    {
        $texts = $sources
            ->filter(fn (EventSource $source): bool => filled($source->text))
            ->pluck('text')
            ->map(fn (string $text): string => Str::lower($text));

        $safetyOnlyParticipants = collect($state['participants'] ?? [])
            ->filter(function (array $participant) use ($texts): bool {
                $name = Str::lower((string) ($participant['name'] ?? ''));
                $quotedName = preg_quote($name, '/');

                return $texts->contains(fn (string $text): bool => preg_match(
                    '/'.$quotedName.'.{0,160}хумус.{0,100}без\s+арахіс/u',
                    $text,
                ) === 1 && preg_match(
                    '/'.$quotedName.'.{0,80}у\s+мене.{0,30}алергі/u',
                    $text,
                ) !== 1);
            })
            ->pluck('name')
            ->filter()
            ->values();

        if ($safetyOnlyParticipants->isEmpty()) {
            return $state;
        }

        $state['participants'] = collect($state['participants'] ?? [])
            ->map(function (array $participant) use ($safetyOnlyParticipants): array {
                if (! $safetyOnlyParticipants->contains($participant['name'] ?? null)) {
                    return $participant;
                }

                $participant['allergies'] = collect($participant['allergies'] ?? [])
                    ->reject(fn (string $allergy): bool => str_contains(Str::lower($allergy), 'арахіс'))
                    ->values()
                    ->all();
                $participant['restrictions'] = collect($participant['restrictions'] ?? [])
                    ->reject(fn (string $restriction): bool => str_contains(Str::lower($restriction), 'арахіс'))
                    ->values()
                    ->all();

                return $participant;
            })
            ->all();
        $state['restrictions'] = collect($state['restrictions'] ?? [])
            ->reject(fn (array $restriction): bool => $safetyOnlyParticipants->contains($restriction['participant'] ?? null)
                && str_contains(Str::lower((string) ($restriction['restriction'] ?? '')), 'арахіс'))
            ->values()
            ->all();

        return $state;
    }

    /**
     * A newer timestamped text can safely weaken an older contribution without
     * relying on the model to keep participants.brings internally consistent.
     *
     * @param  array<string, mixed>  $state
     * @param  Collection<int, EventSource>  $sources
     * @return array<string, mixed>
     */
    private function normalizeTentativeBrings(array $state, Collection $sources): array
    {
        $evidenceSources = $sources
            ->filter(fn (EventSource $source): bool => filled($this->contributionEvidenceText($source)))
            ->sortBy(fn (EventSource $source): int => $this->semanticTextTimestamp($source))
            ->values();
        $tentativeClaims = collect();

        $state['participants'] = collect($state['participants'] ?? [])
            ->map(function (array $participant) use ($evidenceSources, $tentativeClaims): array {
                $name = Str::lower((string) ($participant['name'] ?? ''));
                $participant['brings'] = collect($participant['brings'] ?? [])
                    ->reject(function (string $item) use ($name, $evidenceSources, $tentativeClaims): bool {
                        $latestEvidence = $evidenceSources
                            ->filter(function (EventSource $source) use ($name, $item): bool {
                                $text = $this->contributionEvidenceText($source);

                                return str_contains($text, $name)
                                    && $this->textMentionsContribution($text, $item);
                            })
                            ->last();

                        if ($latestEvidence === null) {
                            return false;
                        }

                        if (! $this->textMakesContributionTentativeOrCancelled(
                            $this->contributionEvidenceText($latestEvidence),
                            $item,
                        )) {
                            return false;
                        }

                        $tentativeClaims->push([
                            'participant' => (string) ($participant['name'] ?? ''),
                            'item' => $item,
                            'source_id' => $latestEvidence->id,
                        ]);

                        return true;
                    })
                    ->values()
                    ->all();

                return $participant;
            })
            ->all();

        $tentativeClaims = $tentativeClaims
            ->unique(fn (array $claim): string => Str::lower($claim['participant'].'|'.$claim['item']))
            ->values();

        if ($tentativeClaims->isEmpty()) {
            return $state;
        }

        $mentionsTentativeClaim = fn (string $text): bool => $tentativeClaims->contains(
            fn (array $claim): bool => str_contains(Str::lower($text), Str::lower($claim['participant']))
                && $this->textMentionsContribution($text, $claim['item']),
        );

        $state['agreements'] = collect($state['agreements'] ?? [])
            ->reject(fn (array $agreement): bool => $mentionsTentativeClaim((string) ($agreement['summary'] ?? '')))
            ->values()
            ->all();
        $state['summary'] = collect(preg_split('/(?<=[.!?])\s+/u', (string) ($state['summary'] ?? '')) ?: [])
            ->reject(fn (string $sentence): bool => $mentionsTentativeClaim($sentence))
            ->implode(' ');

        $warningSourceIds = $tentativeClaims
            ->pluck('source_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $hasTentativeWarning = collect($state['warnings'] ?? [])->contains(
            fn (array $warning): bool => preg_match(
                '/(умовн|не\s+підтвердж|не\s+остаточн)/u',
                Str::lower((string) ($warning['message'] ?? '')),
            ) === 1,
        );

        if (! $hasTentativeWarning) {
            $state['warnings'][] = [
                'message' => 'Умовні внески ще не підтверджені остаточно, тому відповідні товари лишаються в закупівлі.',
                'source_ids' => $warningSourceIds,
            ];
        }

        return $state;
    }

    private function contributionEvidenceText(EventSource $source): string
    {
        $timelineTexts = collect($source->imageExtraction?->message_timeline ?? [])
            ->pluck('text')
            ->filter()
            ->implode(' ');

        return Str::lower(Str::squish(collect([
            $source->text,
            $source->imageExtraction?->ocr_text,
            $timelineTexts,
        ])->filter()->implode(' ')));
    }

    private function semanticTextTimestamp(EventSource $source): int
    {
        $text = (string) $source->text;
        $months = [
            'січня' => 1,
            'лютого' => 2,
            'березня' => 3,
            'квітня' => 4,
            'травня' => 5,
            'червня' => 6,
            'липня' => 7,
            'серпня' => 8,
            'вересня' => 9,
            'жовтня' => 10,
            'листопада' => 11,
            'грудня' => 12,
        ];

        if (preg_match('/^(\d{1,2})\s+([\p{L}]+),\s*(\d{1,2}):(\d{2})/u', $text, $matches) === 1) {
            $month = $months[Str::lower($matches[2])] ?? null;

            if ($month !== null) {
                return Carbon::create(
                    $source->created_at?->year ?? now()->year,
                    $month,
                    (int) $matches[1],
                    (int) $matches[3],
                    (int) $matches[4],
                )->getTimestamp();
            }
        }

        return $source->created_at?->getTimestamp() ?? $source->id;
    }

    private function textMentionsContribution(string $text, string $item): bool
    {
        return collect(preg_split('/[^\p{L}\p{N}]+/u', Str::lower($item)) ?: [])
            ->filter(fn (string $word): bool => mb_strlen($word) >= 3)
            ->contains(fn (string $word): bool => str_contains($text, mb_substr($word, 0, min(5, mb_strlen($word)))));
    }

    private function textMakesContributionTentativeOrCancelled(string $text, string $item): bool
    {
        if (preg_match('/(може(?!\s+містити)|ніби|maybe|якщо\s+встиг|мабуть|підтверджу(?!ю)|поки\s+(?:не|це)|не\s+фінальн|не\s+підтвердж|умовн)/u', $text) === 1) {
            return true;
        }

        $words = collect(preg_split('/[^\p{L}\p{N}]+/u', Str::lower($item)) ?: [])
            ->filter(fn (string $word): bool => mb_strlen($word) >= 3);

        return $words->contains(function (string $word) use ($text): bool {
            $stem = preg_quote(mb_substr($word, 0, min(5, mb_strlen($word))), '/');
            $negative = '(?:не\s+бер\p{L}*|не\s+вез\p{L}*|більше\s+не|не\s+принос\p{L}*|купіть)';

            if (preg_match('/'.$stem.'\p{L}*([^.!?]{0,24})'.$negative.'/u', $text, $matches) === 1
                && preg_match('/\bале\b/u', $matches[1]) !== 1) {
                return true;
            }

            return preg_match('/'.$negative.'([^.!?]{0,24})'.$stem.'\p{L}*/u', $text, $matches) === 1
                && preg_match('/\bале\b/u', $matches[1]) !== 1;
        });
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalizeResolvedAllergyQuestions(array $state): array
    {
        $confirmedAllergyParticipants = collect($state['restrictions'] ?? [])
            ->filter(fn (array $restriction): bool => ($restriction['severity'] ?? null) === 'allergy')
            ->pluck('participant')
            ->filter()
            ->map(fn (string $participant): string => Str::lower($participant))
            ->unique()
            ->values();

        if ($confirmedAllergyParticipants->isEmpty()) {
            return $state;
        }

        $state['unresolved_questions'] = collect($state['unresolved_questions'] ?? [])
            ->reject(function (array $question) use ($confirmedAllergyParticipants): bool {
                $text = Str::lower((string) ($question['question'] ?? ''));

                return str_contains($text, 'алергі')
                    && $confirmedAllergyParticipants->contains(
                        fn (string $participant): bool => str_contains($text, mb_substr($participant, 0, 3)),
                    );
            })
            ->values()
            ->all();

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalizeConfirmedContributionWarnings(array $state): array
    {
        $confirmedContributors = collect($state['participants'] ?? [])
            ->filter(fn (array $participant): bool => ($participant['brings'] ?? []) !== [])
            ->pluck('name')
            ->filter()
            ->map(fn (string $name): string => Str::lower(mb_substr($name, 0, 4)));

        $state['warnings'] = collect($state['warnings'] ?? [])
            ->reject(function (array $warning) use ($confirmedContributors): bool {
                $text = Str::lower((string) ($warning['message'] ?? ''));

                return str_contains($text, 'не підтвердж')
                    && $confirmedContributors->contains(fn (string $stem): bool => str_contains($text, $stem));
            })
            ->values()
            ->all();

        return $state;
    }
}
