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
     * @return array<string, mixed>
     */
    private function removeContributedItems(array $plan, array $state): array
    {
        $contributions = collect($state['participants'] ?? [])
            ->flatMap(fn (array $participant): array => $participant['brings'] ?? [])
            ->values();

        $plan['items'] = collect($plan['items'] ?? [])
            ->reject(function (array $item) use ($contributions): bool {
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
