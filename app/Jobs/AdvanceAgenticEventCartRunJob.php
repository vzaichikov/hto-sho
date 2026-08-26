<?php

namespace App\Jobs;

use App\CartHarnessMode;
use App\CartRunMode;
use App\CartRunPhase;
use App\CartRunStatus;
use App\CartSyncStatus;
use App\Contracts\AgenticSilpoCartRunner;
use App\Contracts\CartProductAgent;
use App\Data\CartAgentAuditData;
use App\Data\SilpoCartContextData;
use App\Models\EventCartRun;
use App\Services\GooseCartStatusService;
use App\Services\HarnessRecorder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

class AdvanceAgenticEventCartRunJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 170;

    public int $tries = 3;

    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30];

    public function __construct(
        public readonly int $runId,
        public readonly int $expectedCursor,
    ) {
        $this->onQueue('ai-events');
    }

    public function uniqueId(): string
    {
        return $this->runId.':agentic:'.$this->expectedCursor;
    }

    public function handle(
        CartProductAgent $preparationAgent,
        AgenticSilpoCartRunner $runner,
        GooseCartStatusService $statuses,
    ): void {
        $run = EventCartRun::query()
            ->with(['event.user.silpoConnection', 'harnessRun'])
            ->find($this->runId);

        if (! $this->canAdvance($run)) {
            return;
        }

        if (! $this->eventIsCurrent($run)) {
            $this->markStale($run, $statuses);

            return;
        }

        match ($run->phase) {
            CartRunPhase::Preparing => $this->prepare($run, $preparationAgent),
            CartRunPhase::Searching => $this->selectNeed($run, $runner, $statuses),
            CartRunPhase::Auditing => $this->audit($run, $runner),
            default => null,
        };
    }

    public function failed(?Throwable $exception): void
    {
        $run = EventCartRun::query()->with(['event', 'harnessRun'])->find($this->runId);

        if (! $this->canAdvance($run)) {
            return;
        }

        $message = 'Agentic MCP-крок не завершився після трьох спроб. Можна дати підказку або повторити цей самий крок.';
        $state = $run->state;
        $state['blocked_phase'] = $run->phase->value;
        $assisted = $run->mode === CartRunMode::Assisted;
        $run->update([
            'status' => $assisted ? CartRunStatus::WaitingForAnswer : CartRunStatus::Failed,
            'state' => $state,
            'blocker' => $assisted ? $message : null,
            'error' => $assisted ? null : 'Agentic MCP-пошук не зміг завершитися.',
            'finished_at' => $assisted ? null : now(),
        ]);
        app(GooseCartStatusService::class)->append($run, $assisted ? 'blocked' : 'warning');
        $run->event->update([
            'cart_sync_status' => $assisted ? CartSyncStatus::Syncing : CartSyncStatus::Failed,
            'cart_sync_error' => $assisted ? null : 'Agentic MCP-пошук не зміг завершитися.',
        ]);

        if (! $assisted && $run->harnessRun !== null && $run->harnessRun->finished_at === null) {
            app(HarnessRecorder::class)->fail(
                $run->harnessRun,
                $exception ?? 'Agentic cart run exhausted its retry budget.',
            );
        }
    }

    private function prepare(EventCartRun $run, CartProductAgent $agent): void
    {
        $state = $run->state;
        $plan = data_get($state, 'plan_snapshot', []);
        $planItems = data_get($plan, 'items', []);

        if (! is_array($planItems) || $planItems === []) {
            $this->finishWithoutProducts($run, 'У погодженому списку немає товарів для Сільпо.');

            return;
        }

        $preparation = $agent->prepare(
            $this->agentEventContext(data_get($state, 'event_context', []), $plan),
            $plan,
            $run->harnessRun,
        );
        $state['needs'] = $preparation->needs;
        $state['current_need_index'] = 0;

        $this->transition($run, [
            'phase' => CartRunPhase::Searching,
            'state' => $state,
        ], [['kind' => 'planning']]);
    }

    private function selectNeed(
        EventCartRun $run,
        AgenticSilpoCartRunner $runner,
        GooseCartStatusService $statuses,
    ): void {
        $state = $run->state;
        $currentIndex = (int) data_get($state, 'current_need_index', 0);
        $need = data_get($state, "needs.{$currentIndex}");

        if (! is_array($need)) {
            $this->transition($run, [
                'phase' => CartRunPhase::Auditing,
                'state' => $state,
            ], [['kind' => 'auditing']]);

            return;
        }

        $plan = data_get($state, 'plan_snapshot', []);
        $result = $runner->selectNeed(
            $this->accessToken($run),
            SilpoCartContextData::fromRunContext($run->cart_context),
            [
                'cart_run_id' => $run->id,
                'mode' => $run->mode->value,
                'people_count' => data_get($plan, 'serves'),
                'food_constraints' => $this->foodConstraints(data_get($state, 'event_context', [])),
                'product_constraints' => $this->productConstraints(data_get($state, 'event_context', []), $plan),
                'current_need' => $need,
                'all_needs' => data_get($state, 'needs', []),
                'staged_items' => $run->staged_items ?? [],
                'event_context' => data_get($state, 'event_context', []),
                'shopping_plan' => $plan,
                'human_answer' => data_get($need, 'human_answer'),
                'native_tool_calls_used' => (int) data_get($need, 'native_tool_calls_used', 0),
            ],
            $run->harnessRun,
            function (string $kind, ?string $query) use ($run, $need, $statuses): void {
                $statuses->append(
                    $run,
                    $kind,
                    (string) data_get($need, 'name'),
                    filled($query) ? ['query' => $query] : [],
                );
            },
        );
        $state['needs'][$currentIndex]['attempts'] = [
            ...data_get($need, 'attempts', []),
            ...$result->attempts,
        ];
        $state['needs'][$currentIndex]['native_tool_calls_used'] =
            (int) data_get($need, 'native_tool_calls_used', 0) + $result->toolCallCount;
        $state['needs'][$currentIndex]['coverage_audit'] = $result->audit->toArray();
        $searchSteps = collect($result->attempts)
            ->map(fn (array $attempt): array => [
                'kind' => 'searching',
                'product' => (string) data_get($need, 'name'),
                'query' => (string) data_get($attempt, 'query'),
            ])
            ->all();

        if ($result->selectedItem !== null) {
            $state['needs'][$currentIndex]['status'] = 'selected';
            $state['needs'][$currentIndex]['selected_item'] = $result->selectedItem;
            $nextIndex = $this->nextUnresolvedNeedIndex($state['needs'], $currentIndex + 1);
            $state['current_need_index'] = $nextIndex ?? count($state['needs']);
            $stagedItems = [...($run->staged_items ?? []), $result->selectedItem];
            $this->transition($run, [
                'phase' => $nextIndex === null ? CartRunPhase::Auditing : CartRunPhase::Searching,
                'state' => $state,
                'staged_items' => $stagedItems,
                'estimated_total' => collect($stagedItems)->sum('estimated_total'),
                'warnings' => array_values(array_unique([...($run->warnings ?? []), ...$result->warnings])),
            ], [
                ...$searchSteps,
                ['kind' => 'weighing', 'product' => (string) data_get($result->selectedItem, 'name')],
                ['kind' => 'selecting', 'product' => (string) data_get($result->selectedItem, 'name')],
            ]);

            return;
        }

        if ($run->mode === CartRunMode::Assisted && blank(data_get($need, 'human_answer'))) {
            $state['blocked_phase'] = CartRunPhase::Searching->value;
            $this->transition($run, [
                'status' => CartRunStatus::WaitingForAnswer,
                'state' => $state,
                'blocker' => $result->question
                    ?: 'Безпечного варіанта для «'.data_get($need, 'name').'» не знайшлося. Підкажіть заміну або дозвольте пропустити.',
                'warnings' => array_values(array_unique([...($run->warnings ?? []), ...$result->warnings])),
            ], [...$searchSteps, ['kind' => 'blocked', 'product' => (string) data_get($need, 'name')]], false);

            return;
        }

        $state['needs'][$currentIndex]['status'] = 'skipped';
        $state['needs'][$currentIndex]['skip_reason'] = $result->question ?: 'Безпечної альтернативи не знайдено.';
        $nextIndex = $this->nextUnresolvedNeedIndex($state['needs'], $currentIndex + 1);
        $state['current_need_index'] = $nextIndex ?? count($state['needs']);
        $this->transition($run, [
            'phase' => $nextIndex === null ? CartRunPhase::Auditing : CartRunPhase::Searching,
            'state' => $state,
            'warnings' => array_values(array_unique([
                ...($run->warnings ?? []),
                ...$result->warnings,
                'Не знайдено: '.data_get($need, 'name').'.',
            ])),
        ], [...$searchSteps, ['kind' => 'warning', 'product' => (string) data_get($need, 'name')]]);
    }

    private function audit(EventCartRun $run, AgenticSilpoCartRunner $runner): void
    {
        $state = $run->state;
        $needs = data_get($state, 'needs', []);
        $audit = $runner->audit([
            'people_count' => data_get($state, 'plan_snapshot.serves'),
            'shopping_plan' => data_get($state, 'plan_snapshot'),
            'food_constraints' => $this->foodConstraints(data_get($state, 'event_context', [])),
            'needs' => $needs,
            'staged_items' => $run->staged_items ?? [],
            'human_answer' => data_get($state, 'audit_answer'),
        ], $run->harnessRun);
        $this->guardAuditKeys($audit, $needs);
        $remainingNeeds = collect($needs)->reject(
            fn (array $need): bool => data_get($need, 'status') === 'selected',
        );

        if ($remainingNeeds->isEmpty()) {
            $audit = $this->normalizeCompletedAudit($needs, $audit);
        }

        $state['final_audit'] = $audit->toArray();
        $hasHumanAnswer = filled(data_get($state, 'audit_answer'));
        unset($state['audit_answer']);
        $hasGap = $remainingNeeds->contains(
            fn (array $need): bool => data_get($need, 'optional') !== true,
        );

        if ($hasGap && $run->mode === CartRunMode::Assisted && ! $hasHumanAnswer) {
            $state['blocked_phase'] = CartRunPhase::Auditing->value;
            $this->transition($run, [
                'status' => CartRunStatus::WaitingForAnswer,
                'state' => $state,
                'blocker' => $audit->question
                    ?? 'Гусь не знайшов безпечного способу закрити весь список. Що робимо з непокритими позиціями?',
                'warnings' => array_values(array_unique([...($run->warnings ?? []), ...$audit->warnings])),
            ], [['kind' => 'blocked']], false);

            return;
        }

        $evidenceWarnings = collect($run->staged_items ?? [])
            ->pluck('review_note')
            ->filter(fn (mixed $note): bool => is_string($note) && filled($note))
            ->values()
            ->all();
        $state['has_unmet_needs'] = $hasGap;
        $this->transition($run, [
            'status' => CartRunStatus::WaitingForConfirmation,
            'phase' => CartRunPhase::ReadyToCommit,
            'state' => $state,
            'warnings' => array_values(array_unique([
                ...($run->warnings ?? []),
                ...$evidenceWarnings,
                ...$audit->warnings,
            ])),
        ], [['kind' => 'auditing']], false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{kind: string, product?: string, query?: string}>  $steps
     */
    private function transition(EventCartRun $run, array $attributes, array $steps, bool $dispatchNext = true): void
    {
        $updatedRun = DB::transaction(function () use ($run, $attributes, $steps): ?EventCartRun {
            $lockedRun = EventCartRun::query()->lockForUpdate()->find($run->id);

            if (! $this->canAdvance($lockedRun)) {
                return null;
            }

            $lockedRun->update([...$attributes, 'cursor' => $lockedRun->cursor + 1]);
            $statuses = app(GooseCartStatusService::class);

            foreach ($steps as $step) {
                $statuses->append(
                    $lockedRun,
                    $step['kind'],
                    $step['product'] ?? null,
                    isset($step['query']) ? ['query' => $step['query']] : [],
                );
            }

            return $lockedRun;
        });

        if ($updatedRun !== null && $dispatchNext) {
            self::dispatch($updatedRun->id, $updatedRun->cursor);
        }
    }

    private function finishWithoutProducts(EventCartRun $run, string $warning): void
    {
        DB::transaction(function () use ($run, $warning): void {
            $lockedRun = EventCartRun::query()->lockForUpdate()->findOrFail($run->id);
            $lockedRun->update([
                'status' => CartRunStatus::Partial,
                'phase' => CartRunPhase::Finished,
                'warnings' => [$warning],
                'finished_at' => now(),
                'cursor' => $lockedRun->cursor + 1,
            ]);
            app(GooseCartStatusService::class)->append($lockedRun, 'warning');
            $lockedRun->event()->update([
                'cart_sync_status' => CartSyncStatus::Partial,
                'cart_sync_error' => $warning,
            ]);
        });
    }

    private function markStale(EventCartRun $run, GooseCartStatusService $statuses): void
    {
        $run->update([
            'status' => CartRunStatus::Stale,
            'phase' => CartRunPhase::Finished,
            'error' => 'Список події змінився. Запустіть Гуся ще раз з актуального списку.',
            'finished_at' => now(),
        ]);
        $statuses->append($run, 'warning');
        $run->event->update([
            'cart_sync_status' => CartSyncStatus::Stale,
            'cart_sync_error' => $run->error,
        ]);
    }

    private function canAdvance(?EventCartRun $run): bool
    {
        return $run !== null
            && $run->harness_mode === CartHarnessMode::Agentic
            && $run->status === CartRunStatus::Running
            && $run->cursor === $this->expectedCursor;
    }

    private function eventIsCurrent(EventCartRun $run): bool
    {
        return $run->event->state_version === $run->plan_state_version
            && $run->event->isPlanCurrent();
    }

    private function accessToken(EventCartRun $run): string
    {
        $connection = $run->event->user->silpoConnection;

        if ($connection === null || $connection->revoked_at !== null
            || ($connection->expires_at !== null && $connection->expires_at->isPast())) {
            throw new UnexpectedValueException('Silpo connection is not available for the cart run.');
        }

        return $connection->access_token;
    }

    /** @return array<string, mixed> */
    private function agentEventContext(array $eventContext, array $plan): array
    {
        return [
            'summary' => data_get($eventContext, 'summary'),
            'people_count' => data_get($plan, 'serves'),
            'restrictions' => $this->foodConstraints($eventContext),
            'warnings' => data_get($eventContext, 'warnings', []),
        ];
    }

    /** @return array<int, mixed> */
    private function foodConstraints(array $eventContext): array
    {
        $participantConstraints = collect(data_get($eventContext, 'participants', []))
            ->filter(fn (mixed $participant): bool => is_array($participant))
            ->flatMap(function (array $participant): array {
                $name = (string) data_get($participant, 'name', 'Учасник');

                return [
                    ...collect(data_get($participant, 'allergies', []))->map(fn (mixed $value): array => [
                        'participant' => $name,
                        'type' => 'allergy',
                        'severity' => 'hard',
                        'value' => $value,
                    ])->all(),
                    ...collect(data_get($participant, 'restrictions', []))->map(fn (mixed $value): array => [
                        'participant' => $name,
                        'type' => 'restriction',
                        'severity' => 'hard',
                        'value' => $value,
                    ])->all(),
                    ...collect(data_get($participant, 'preferences', []))->map(fn (mixed $value): array => [
                        'participant' => $name,
                        'type' => 'preference',
                        'severity' => 'soft',
                        'value' => $value,
                    ])->all(),
                ];
            });

        return collect(data_get($eventContext, 'restrictions', []))
            ->concat($participantConstraints)
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function productConstraints(array $eventContext, array $plan): array
    {
        return collect(data_get($eventContext, 'agreements', []))
            ->filter(fn (mixed $agreement): bool => is_array($agreement))
            ->pluck('summary')
            ->filter(fn (mixed $summary): bool => is_string($summary)
                && Str::contains(Str::lower($summary), [
                    'без ', 'не куп', 'лише безглютен', 'не вжив', 'не їсть',
                ]))
            ->concat(data_get($plan, 'warnings', []))
            ->filter(fn (mixed $constraint): bool => is_string($constraint) && filled($constraint))
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<int, array<string, mixed>> $needs */
    private function guardAuditKeys(CartAgentAuditData $audit, array $needs): void
    {
        $knownKeys = collect($needs)->pluck('key');
        $reportedKeys = collect([...$audit->coveredNeedKeys, ...$audit->remainingNeedKeys]);
        $duplicatedKeys = collect($audit->coveredNeedKeys)->intersect($audit->remainingNeedKeys);

        if ($reportedKeys->diff($knownKeys)->isNotEmpty()
            || $knownKeys->diff($reportedKeys)->isNotEmpty()
            || $duplicatedKeys->isNotEmpty()) {
            throw new UnexpectedValueException('Agent audit did not account for every known need exactly once.');
        }
    }

    /** @param array<int, array<string, mixed>> $needs */
    private function normalizeCompletedAudit(array $needs, CartAgentAuditData $audit): CartAgentAuditData
    {
        return new CartAgentAuditData(
            complete: true,
            coveredNeedKeys: collect($needs)->pluck('key')->filter()->values()->all(),
            remainingNeedKeys: [],
            enoughForPeople: true,
            warnings: $audit->complete ? $audit->warnings : [],
            revisitNeedKey: null,
            revisitQuery: null,
            question: null,
        );
    }

    /** @param array<int, array<string, mixed>> $needs */
    private function nextUnresolvedNeedIndex(array $needs, int $startAt): ?int
    {
        foreach ($needs as $index => $need) {
            if ($index >= $startAt && ! in_array(data_get($need, 'status'), ['selected', 'skipped'], true)) {
                return $index;
            }
        }

        return null;
    }
}
