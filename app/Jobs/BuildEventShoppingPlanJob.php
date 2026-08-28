<?php

namespace App\Jobs;

use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use App\HarnessEntryKind;
use App\HarnessRunStatus;
use App\HarnessRunType;
use App\Models\Event;
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

class BuildEventShoppingPlanJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    public int $tries = 3;

    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $eventId,
        public readonly int $stateVersion,
    ) {
        $this->onQueue('ai-events');
    }

    public function uniqueId(): string
    {
        return $this->eventId.':'.$this->stateVersion;
    }

    public function handle(ContextAnalysisService $analysis, HarnessRecorder $harnessRecorder): void
    {
        $event = Event::query()->find($this->eventId);

        if (! $this->canBuild($event)) {
            return;
        }

        $event->update([
            'plan_generation_status' => PlanGenerationStatus::Processing,
            'plan_generation_error' => null,
        ]);

        $harnessRun = $harnessRecorder->start(
            event: $event,
            type: HarnessRunType::ShoppingPlan,
            correlationId: 'state-'.$this->stateVersion,
            metadata: ['state_version' => $this->stateVersion],
        );
        $harnessRecorder->append(
            run: $harnessRun,
            kind: HarnessEntryKind::Action,
            title: 'Гусь почав будувати список',
        );

        $planCorrections = $this->planCorrections($event);
        $plan = $analysis->buildShoppingPlan([
            'title' => $event->title,
            'description' => $event->description,
            'alcohol_planned' => $event->alcohol_planned,
            'people_count' => $event->people_count,
            'budget_amount' => $event->budget_amount,
            'currency' => $event->currency,
        ], $event->state, $planCorrections, $harnessRun)->plan;
        $plan = $this->removeContributedItems($plan, $event->state);
        $plan = $this->normalizeServingQuantities($plan);
        $plan = $this->normalizeOptionalItems($plan, $event->state);

        $this->guardExplicitShoppingRequirements($plan, $event->state);
        $this->guardPlanSafety($plan, $event->state, $event->alcohol_planned);

        DB::transaction(function () use ($plan): void {
            $event = Event::query()->lockForUpdate()->find($this->eventId);

            if (! $this->canBuild($event)) {
                return;
            }

            $event->update([
                'shopping_plan' => $plan,
                'plan_state_version' => $this->stateVersion,
                'plan_generation_status' => PlanGenerationStatus::Ready,
                'plan_generation_error' => null,
            ]);
        });

        $harnessRecorder->append(
            run: $harnessRun,
            kind: HarnessEntryKind::Action,
            title: 'Список збережено',
            message: sprintf('Позицій: %d.', count($plan['items'] ?? [])),
        );
        $harnessRecorder->finish($harnessRun);
    }

    public function failed(?Throwable $exception): void
    {
        HarnessRun::query()
            ->where('event_id', $this->eventId)
            ->where('type', HarnessRunType::ShoppingPlan)
            ->where('correlation_id', 'state-'.$this->stateVersion)
            ->update([
                'status' => HarnessRunStatus::Failed,
                'error' => mb_substr($exception?->getMessage() ?? 'Не вдалося скласти список.', 0, 2000),
                'finished_at' => now(),
            ]);

        Event::query()
            ->whereKey($this->eventId)
            ->where('state_version', $this->stateVersion)
            ->update([
                'plan_generation_status' => PlanGenerationStatus::Failed,
                'plan_generation_error' => mb_substr(
                    $exception?->getMessage() ?? 'Не вдалося скласти список.',
                    0,
                    2000,
                ),
            ]);
    }

    private function canBuild(?Event $event): bool
    {
        return $event !== null
            && $event->state !== null
            && $event->state_version === $this->stateVersion
            && ! $event->hasUnanalyzedChanges();
    }

    /**
     * @return array<int, array{source_id: int, instruction: string, submitted_at: ?string, base_plan_state_version: ?int, base_plan: array<string, mixed>}>
     */
    private function planCorrections(Event $event): array
    {
        return $event->sources()
            ->where('type', EventSourceType::Text)
            ->where('origin', 'plan_correction')
            ->where('status', EventSourceStatus::Processed)
            ->where('inclusion', '!=', EventSourceInclusion::Dismissed)
            ->oldest('created_at')
            ->oldest('id')
            ->get()
            ->map(function (EventSource $source): ?array {
                $basePlan = $source->metadata['base_plan'] ?? null;

                if (! is_array($basePlan) || blank($source->text)) {
                    return null;
                }

                return [
                    'source_id' => $source->id,
                    'instruction' => $source->text,
                    'submitted_at' => $source->created_at?->toIso8601String(),
                    'base_plan_state_version' => $source->metadata['base_plan_state_version'] ?? null,
                    'base_plan' => $basePlan,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $state
     */
    private function guardPlanSafety(array $plan, array $state, bool $alcoholPlanned): void
    {
        $questions = collect($state['unresolved_questions'] ?? []);
        $questionKeys = $questions->pluck('key')->filter()->values();
        $unansweredKeys = collect($plan['unanswered_question_keys'] ?? []);

        if ($unansweredKeys->diff($questionKeys)->isNotEmpty()) {
            throw new UnexpectedValueException('Shopping plan contains an unknown question key.');
        }

        $missingBlockingKeys = $questions
            ->where('blocking', true)
            ->pluck('key')
            ->diff($unansweredKeys);

        if ($missingBlockingKeys->isNotEmpty()) {
            throw new UnexpectedValueException('Shopping plan ignores a blocking question.');
        }

        $hasUnansweredAlcoholQuestion = $questions
            ->filter(fn (array $question): bool => str_contains(
                Str::lower(($question['question'] ?? '').' '.($question['impact'] ?? '')),
                'алкогол',
            ))
            ->pluck('key')
            ->intersect($unansweredKeys)
            ->isNotEmpty();
        $hasAlcoholItems = collect($plan['items'] ?? [])->contains('category', 'alcohol');

        if (! $alcoholPlanned && $hasUnansweredAlcoholQuestion && $hasAlcoholItems) {
            throw new UnexpectedValueException('Shopping plan assumes alcohol before the organizer answers.');
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $state
     */
    private function guardExplicitShoppingRequirements(array $plan, array $state): void
    {
        $items = collect($plan['items'] ?? []);
        $participantNames = collect($state['participants'] ?? [])
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name) && filled($name))
            ->values()
            ->all();

        foreach ($state['shopping_requirements'] ?? [] as $requirement) {
            $matchedItems = $this->shoppingRequirementItemIndexes($items, $requirement, $participantNames)
                ->map(fn (int $index): array => $items->get($index));

            if ($matchedItems->isEmpty()) {
                throw new UnexpectedValueException('Shopping plan omitted an explicit shopping requirement.');
            }

            if (($requirement['quantity'] ?? null) !== null
                && abs($matchedItems->sum(fn (array $item): float => (float) $item['quantity']) - (float) $requirement['quantity']) > 0.0001) {
                throw new UnexpectedValueException('Shopping plan changed an explicit shopping quantity.');
            }

            if (($requirement['unit'] ?? null) !== null
                && $matchedItems->contains(fn (array $item): bool => Str::lower(
                    Str::squish((string) $item['unit']),
                ) !== Str::lower(Str::squish((string) $requirement['unit'])))) {
                throw new UnexpectedValueException('Shopping plan changed an explicit shopping unit.');
            }
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $requirement
     * @param  array<int, string>  $participantNames
     * @return Collection<int, int>
     */
    private function shoppingRequirementItemIndexes(
        Collection $items,
        array $requirement,
        array $participantNames,
    ): Collection {
        $matchedIndexes = collect();
        $requiredNames = $this->atomicShoppingRequirementNames(
            (string) ($requirement['name'] ?? ''),
            $participantNames,
        );

        foreach ($requiredNames as $requiredName) {
            $matchedIndex = $items->search(fn (array $item, int $index): bool => ! $matchedIndexes->contains($index)
                && $this->shoppingItemCoversRequirementName((string) ($item['name'] ?? ''), $requiredName));

            if ($matchedIndex === false) {
                return collect();
            }

            $matchedIndexes->push($matchedIndex);
        }

        return $matchedIndexes;
    }

    /**
     * @param  array<int, string>  $participantNames
     * @return Collection<int, string>
     */
    private function atomicShoppingRequirementNames(string $name, array $participantNames): Collection
    {
        preg_match_all('/\(([^()]*)\)/u', $name, $matches);

        $listedNames = collect($matches[1] ?? [])
            ->flatMap(fn (string $list): array => preg_split(
                '/\s*(?:[,;\/]|(?<!\p{L})(?:і|й|та)(?!\p{L}))\s*/u',
                $list,
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [])
            ->map(fn (string $item): string => $this->withoutParticipantQualifier($item, $participantNames))
            ->filter()
            ->values();

        if ($listedNames->count() > 1) {
            return $listedNames;
        }

        return collect([$this->withoutParticipantQualifier($name, $participantNames)])
            ->filter()
            ->values();
    }

    /** @param array<int, string> $participantNames */
    private function withoutParticipantQualifier(string $name, array $participantNames): string
    {
        $participantPrefixes = collect($participantNames)
            ->map(fn (string $participantName): string => collect(
                preg_split('/[^\p{L}]+/u', Str::lower($participantName), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            )->first() ?? '')
            ->filter()
            ->map(function (string $participantName): string {
                $length = min(3, max(2, mb_strlen($participantName) - 1));

                return mb_substr($participantName, 0, $length);
            })
            ->unique()
            ->values();

        if ($participantPrefixes->isEmpty()) {
            return Str::squish($name);
        }

        $withoutQualifier = preg_replace_callback(
            '/\s+для\s+([\p{L}]+)/iu',
            function (array $matches) use ($participantPrefixes): string {
                $qualifier = Str::lower($matches[1]);

                return $participantPrefixes->contains(
                    fn (string $prefix): bool => str_starts_with($qualifier, $prefix),
                ) ? '' : $matches[0];
            },
            $name,
        );

        return Str::squish($withoutQualifier ?? $name);
    }

    private function shoppingItemCoversRequirementName(string $itemName, string $requirementName): bool
    {
        $normalizedItemName = Str::lower(Str::squish($itemName));
        $normalizedRequirementName = Str::lower(Str::squish($requirementName));

        if ($normalizedItemName === $normalizedRequirementName) {
            return true;
        }

        $itemTokens = $this->shoppingIdentityTokens($normalizedItemName);
        $requirementTokens = $this->shoppingIdentityTokens($normalizedRequirementName);

        return $requirementTokens->isNotEmpty()
            && $requirementTokens->diff($itemTokens)->isEmpty();
    }

    /** @return Collection<int, string> */
    private function shoppingIdentityTokens(string $name): Collection
    {
        return collect(preg_split('/[^\p{L}\p{N}]+/u', Str::lower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->reject(fn (string $token): bool => in_array($token, ['для', 'до', 'на', 'із', 'зі', 'та'], true))
            ->unique()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalizeOptionalItems(array $plan, array $state): array
    {
        $items = collect($plan['items'] ?? []);
        $participantNames = collect($state['participants'] ?? [])
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name) && filled($name))
            ->values()
            ->all();
        $explicitRequirementIndexes = collect($state['shopping_requirements'] ?? [])
            ->flatMap(fn (array $requirement): Collection => $this->shoppingRequirementItemIndexes(
                $items,
                $requirement,
                $participantNames,
            ))
            ->unique();

        $plan['items'] = $items
            ->map(function (array $item, int $index) use ($explicitRequirementIndexes): array {
                return [
                    ...$item,
                    'optional' => $explicitRequirementIndexes->contains($index)
                        ? false
                        : (bool) data_get($item, 'optional', false),
                    'minimum_distinct_products' => (int) data_get(
                        $item,
                        'minimum_distinct_products',
                        1,
                    ),
                ];
            })
            ->values()
            ->all();

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function removeContributedItems(array $plan, array $state): array
    {
        $contributions = collect($state['participants'] ?? [])
            ->flatMap(fn (array $participant): array => $participant['brings'] ?? [])
            ->values();
        $explicitRequirementNames = collect($state['shopping_requirements'] ?? [])
            ->pluck('name')
            ->filter()
            ->map(fn (string $name): string => Str::lower(Str::squish($name)));

        $plan['items'] = collect($plan['items'] ?? [])
            ->reject(function (array $item) use ($contributions, $explicitRequirementNames): bool {
                if ($explicitRequirementNames->contains(
                    Str::lower(Str::squish((string) ($item['name'] ?? ''))),
                )) {
                    return false;
                }

                $itemStems = $this->significantStems((string) ($item['name'] ?? ''));

                return $contributions->contains(function (string $contribution) use ($itemStems): bool {
                    return $itemStems->intersect($this->significantStems($contribution))->isNotEmpty();
                });
            })
            ->values()
            ->all();

        return $plan;
    }

    /** @return Collection<int, string> */
    private function significantStems(string $text): Collection
    {
        return collect(preg_split('/[^\p{L}\p{N}]+/u', Str::lower($text)) ?: [])
            ->filter(fn (string $word): bool => mb_strlen($word) >= 4)
            ->map(fn (string $word): string => mb_substr($word, 0, 4))
            ->reject(fn (string $stem): bool => in_array($stem, ['пачк', 'набі', 'упак', 'пляш', 'паке'], true))
            ->unique()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function normalizeServingQuantities(array $plan): array
    {
        $serves = (int) ($plan['serves'] ?? 0);

        if ($serves < 1) {
            return $plan;
        }

        $plan['items'] = collect($plan['items'] ?? [])
            ->map(function (array $item) use ($serves): array {
                $name = Str::lower((string) ($item['name'] ?? ''));
                $unit = Str::lower((string) ($item['unit'] ?? ''));

                if (str_contains($name, 'овоч') && str_contains($name, 'грил') && $unit === 'кг') {
                    $item['quantity'] = min((float) $item['quantity'], round($serves * 0.375, 2));
                }

                return $item;
            })
            ->all();

        return $plan;
    }
}
