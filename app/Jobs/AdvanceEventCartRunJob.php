<?php

namespace App\Jobs;

use App\CartRunMode;
use App\CartRunPhase;
use App\CartRunStatus;
use App\CartSyncStatus;
use App\Contracts\CartProductAgent;
use App\Contracts\SilpoCartGateway;
use App\Data\CartAgentAuditData;
use App\Data\CartAgentDecisionData;
use App\Data\SilpoCartContextData;
use App\Models\EventCartRun;
use App\Services\CartQuantityCalculator;
use App\Services\GooseCartStatusService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

class AdvanceEventCartRunJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 80;

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
        return $this->runId.':'.$this->expectedCursor;
    }

    public function handle(
        CartProductAgent $agent,
        SilpoCartGateway $silpo,
        CartQuantityCalculator $quantities,
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
            CartRunPhase::Preparing => $this->prepare($run, $agent, $statuses),
            CartRunPhase::Searching => $this->search($run, $silpo, $quantities, $statuses),
            CartRunPhase::Inspecting => $this->inspect($run, $silpo, $statuses),
            CartRunPhase::Deciding => $this->decide($run, $agent, $quantities, $statuses),
            CartRunPhase::Auditing => $this->audit($run, $agent, $quantities, $statuses),
            default => null,
        };
    }

    public function failed(?Throwable $exception): void
    {
        $run = EventCartRun::query()->with('event')->find($this->runId);

        if (! $this->canAdvance($run)) {
            return;
        }

        $message = 'Гусь тричі перечепився на цьому кроці. Можна підказати або просто попросити спробувати ще раз.';
        $state = $run->state;
        $state['blocked_phase'] = $run->phase->value;
        $assisted = $run->mode === CartRunMode::Assisted;
        $run->update([
            'status' => $assisted ? CartRunStatus::WaitingForAnswer : CartRunStatus::Failed,
            'state' => $state,
            'blocker' => $assisted ? $message : null,
            'error' => $assisted ? null : 'Гусь не зміг завершити автоматичний пошук.',
            'finished_at' => $assisted ? null : now(),
        ]);
        app(GooseCartStatusService::class)->append($run, $assisted ? 'blocked' : 'warning');
        $run->event->update([
            'cart_sync_status' => $assisted ? CartSyncStatus::Syncing : CartSyncStatus::Failed,
            'cart_sync_error' => $assisted
                ? null
                : 'Гусь не зміг завершити автоматичний пошук.',
        ]);
    }

    private function prepare(
        EventCartRun $run,
        CartProductAgent $agent,
        GooseCartStatusService $statuses,
    ): void {
        $state = $run->state;
        $plan = data_get($state, 'plan_snapshot', []);
        $planItems = data_get($plan, 'items', []);

        if (! is_array($planItems) || $planItems === []) {
            $this->finishWithoutProducts($run, $statuses, 'У погодженому списку немає товарів для Сільпо.');

            return;
        }

        $preparation = $agent->prepare(
            $this->agentEventContext(data_get($state, 'event_context', []), $plan),
            $plan,
            $run->harnessRun,
        );
        $state['needs'] = $preparation->needs;
        $state['current_need_index'] = 0;

        $this->transition(
            $run,
            [
                'phase' => CartRunPhase::Searching,
                'state' => $state,
            ],
            [['kind' => 'planning']],
        );
    }

    private function search(
        EventCartRun $run,
        SilpoCartGateway $silpo,
        CartQuantityCalculator $quantities,
        GooseCartStatusService $statuses,
    ): void {
        $state = $run->state;
        $currentIndex = (int) data_get($state, 'current_need_index', 0);
        $need = data_get($state, "needs.{$currentIndex}");

        if (! is_array($need)) {
            $this->transition($run, ['phase' => CartRunPhase::Auditing, 'state' => $state], [
                ['kind' => 'auditing'],
            ]);

            return;
        }

        if (count(data_get($need, 'attempts', [])) >= 6) {
            $this->resolveUnmatched($run, $state, $currentIndex, $need, $statuses);

            return;
        }

        $query = $quantities->normalizeSearchQuery((string) data_get($need, 'search_query'));

        if ($query === '') {
            $query = $quantities->normalizeSearchQuery((string) data_get($need, 'name'));
        }

        $products = $silpo->searchProducts(
            $this->accessToken($run),
            SilpoCartContextData::fromRunContext($run->cart_context),
            $query,
            harnessRun: $run->harnessRun,
        );
        $candidates = collect($products)
            ->map(fn (array $product): array => $this->candidate(
                $product,
                SilpoCartContextData::fromRunContext($run->cart_context),
            ))
            ->filter(fn (array $product): bool => $product['product_id'] !== '')
            ->values()
            ->all();
        $state['needs'][$currentIndex]['attempts'][] = [
            'query' => $query,
            'total_found' => count($candidates),
        ];
        $state['last_candidates'] = $candidates;
        $state['last_details'] = null;
        $stepDefinitions = [['kind' => 'searching', 'product' => (string) data_get($need, 'name')]];

        if (($currentIndex + count($state['needs'][$currentIndex]['attempts'])) % 4 === 0) {
            $stepDefinitions[] = ['kind' => 'distraction'];
        }

        $this->transition(
            $run,
            [
                'phase' => CartRunPhase::Deciding,
                'state' => $state,
            ],
            $stepDefinitions,
        );
    }

    private function inspect(
        EventCartRun $run,
        SilpoCartGateway $silpo,
        GooseCartStatusService $statuses,
    ): void {
        $state = $run->state;
        $currentIndex = (int) data_get($state, 'current_need_index', 0);
        $productId = (string) data_get($state, 'inspect_product_id');
        $candidate = collect(data_get($state, 'last_candidates', []))->firstWhere('product_id', $productId);

        if (! is_array($candidate) || blank(data_get($candidate, 'slug'))) {
            throw new UnexpectedValueException('Agent requested details for an unknown catalog product.');
        }

        $details = $silpo->getProductDetails(
            $this->accessToken($run),
            SilpoCartContextData::fromRunContext($run->cart_context),
            (string) $candidate['slug'],
            $run->harnessRun,
        );
        $state['last_details'] = [
            'product_id' => $productId,
            'name' => $candidate['name'],
            'attributes' => data_get($details, 'attributes', []),
            'description' => data_get($details, 'description'),
            'nutrition' => data_get($details, 'nutrition'),
        ];
        $state['needs'][$currentIndex]['inspected_products'][] = $productId;
        unset($state['inspect_product_id']);

        $this->transition(
            $run,
            ['phase' => CartRunPhase::Deciding, 'state' => $state],
            [['kind' => 'inspecting', 'product' => (string) $candidate['name']]],
        );
    }

    private function decide(
        EventCartRun $run,
        CartProductAgent $agent,
        CartQuantityCalculator $quantities,
        GooseCartStatusService $statuses,
    ): void {
        $state = $run->state;
        $currentIndex = (int) data_get($state, 'current_need_index', 0);
        $need = data_get($state, "needs.{$currentIndex}");

        if (! is_array($need)) {
            $this->transition($run, ['phase' => CartRunPhase::Auditing, 'state' => $state], [
                ['kind' => 'auditing'],
            ]);

            return;
        }

        $decision = $agent->decide([
            'mode' => $run->mode->value,
            'people_count' => data_get($state, 'plan_snapshot.serves'),
            'food_constraints' => $this->foodConstraints(data_get($state, 'event_context', [])),
            'current_need' => $need,
            'all_needs' => collect(data_get($state, 'needs', []))->map(fn (array $item): array => Arr::only($item, [
                'key', 'name', 'quantity', 'unit', 'status', 'selected_item',
            ]))->all(),
            'candidates' => data_get($state, 'last_candidates', []),
            'inspected_details' => data_get($state, 'last_details'),
            'staged_items' => $run->staged_items ?? [],
        ], $run->harnessRun);
        $this->guardAuditKeys($decision->audit, data_get($state, 'needs', []));

        match ($decision->action) {
            'select' => $this->selectCandidate($run, $state, $currentIndex, $need, $decision, $quantities),
            'retry' => $this->retrySearch($run, $state, $currentIndex, $need, $decision, $quantities),
            'inspect' => $this->inspectCandidate($run, $state, $currentIndex, $need, $decision),
            'skip', 'ask' => $this->handleUnresolvedDecision($run, $state, $currentIndex, $need, $decision, $statuses),
        };
    }

    private function audit(
        EventCartRun $run,
        CartProductAgent $agent,
        CartQuantityCalculator $quantities,
        GooseCartStatusService $statuses,
    ): void {
        $state = $run->state;
        $audit = $agent->audit([
            'people_count' => data_get($state, 'plan_snapshot.serves'),
            'shopping_plan' => data_get($state, 'plan_snapshot'),
            'food_constraints' => $this->foodConstraints(data_get($state, 'event_context', [])),
            'needs' => data_get($state, 'needs', []),
            'staged_items' => $run->staged_items ?? [],
            'human_answer' => data_get($state, 'audit_answer'),
        ], $run->harnessRun);
        $this->guardAuditKeys($audit, data_get($state, 'needs', []));
        $state['final_audit'] = $audit->toArray();
        $hasHumanAnswer = filled(data_get($state, 'audit_answer'));
        unset($state['audit_answer']);
        $remainingNeeds = collect(data_get($state, 'needs', []))->reject(
            fn (array $need): bool => data_get($need, 'status') === 'selected',
        );
        $hasGap = $remainingNeeds->isNotEmpty() || ! $audit->complete || ! $audit->enoughForPeople;

        if ($hasGap && $this->revisitNeed($run, $state, $audit, $quantities)) {
            return;
        }

        if ($hasGap && $run->mode === CartRunMode::Assisted && ! $hasHumanAnswer) {
            $question = $audit->question
                ?? 'Гусь не знайшов безпечного способу закрити весь список. Що робимо з непокритими позиціями?';
            $state['blocked_phase'] = CartRunPhase::Auditing->value;
            $this->transition(
                $run,
                [
                    'status' => CartRunStatus::WaitingForAnswer,
                    'state' => $state,
                    'blocker' => $question,
                    'warnings' => array_values(array_unique([...($run->warnings ?? []), ...$audit->warnings])),
                ],
                [['kind' => 'blocked']],
                dispatchNext: false,
            );

            return;
        }

        $state['has_unmet_needs'] = $hasGap;
        $this->transition(
            $run,
            [
                'phase' => CartRunPhase::ReadyToCommit,
                'state' => $state,
                'warnings' => array_values(array_unique([...($run->warnings ?? []), ...$audit->warnings])),
            ],
            [['kind' => 'auditing']],
            commitNext: true,
        );
    }

    /** @param array<string, mixed> $state */
    private function selectCandidate(
        EventCartRun $run,
        array $state,
        int $currentIndex,
        array $need,
        CartAgentDecisionData $decision,
        CartQuantityCalculator $quantities,
    ): void {
        $candidate = collect(data_get($state, 'last_candidates', []))
            ->firstWhere('product_id', $decision->selectedProductId);

        if (! is_array($candidate)) {
            throw new UnexpectedValueException('Agent selected a product outside the current search result.');
        }

        $quantity = $quantities->quantityFor($need, $candidate, (float) $decision->quantity);
        $selectedItem = [
            ...$candidate,
            'need_key' => data_get($need, 'key'),
            'need_name' => data_get($need, 'name'),
            'quantity' => $quantity,
            'estimated_total' => $quantities->estimatedTotal($candidate, $quantity),
            'source' => 'goose',
        ];
        $state['needs'][$currentIndex]['status'] = 'selected';
        $state['needs'][$currentIndex]['selected_item'] = $selectedItem;
        $state['needs'][$currentIndex]['coverage_audit'] = $decision->audit->toArray();
        $nextIndex = $this->nextUnresolvedNeedIndex($state['needs'], $currentIndex + 1);
        $state['current_need_index'] = $nextIndex ?? count($state['needs']);
        $state['last_candidates'] = [];
        $state['last_details'] = null;
        $stagedItems = [...($run->staged_items ?? []), $selectedItem];
        $nextPhase = $nextIndex !== null
            ? CartRunPhase::Searching
            : CartRunPhase::Auditing;

        $this->transition(
            $run,
            [
                'phase' => $nextPhase,
                'state' => $state,
                'staged_items' => $stagedItems,
                'estimated_total' => collect($stagedItems)->sum('estimated_total'),
            ],
            [
                ['kind' => 'weighing', 'product' => (string) data_get($candidate, 'name')],
                ['kind' => 'selecting', 'product' => (string) data_get($candidate, 'name')],
            ],
        );
    }

    /** @param array<string, mixed> $state */
    private function retrySearch(
        EventCartRun $run,
        array $state,
        int $currentIndex,
        array $need,
        CartAgentDecisionData $decision,
        CartQuantityCalculator $quantities,
    ): void {
        $query = $quantities->normalizeSearchQuery((string) $decision->query);
        $attemptedQueries = collect(data_get($need, 'attempts', []))->pluck('query');

        if ($query === '' || $attemptedQueries->contains(fn (string $attempt): bool => Str::lower($attempt) === Str::lower($query))) {
            throw new UnexpectedValueException('Agent repeated a search query.');
        }

        $state['needs'][$currentIndex]['search_query'] = $query;
        $state['needs'][$currentIndex]['coverage_audit'] = $decision->audit->toArray();
        $kind = str_contains(Str::lower($decision->reason), 'дорог') ? 'expensive' : 'retry';

        $this->transition(
            $run,
            ['phase' => CartRunPhase::Searching, 'state' => $state],
            [['kind' => $kind, 'product' => (string) data_get($need, 'name')]],
        );
    }

    /** @param array<string, mixed> $state */
    private function inspectCandidate(
        EventCartRun $run,
        array $state,
        int $currentIndex,
        array $need,
        CartAgentDecisionData $decision,
    ): void {
        $candidate = collect(data_get($state, 'last_candidates', []))
            ->firstWhere('product_id', $decision->selectedProductId);
        $inspectedProducts = collect(data_get($need, 'inspected_products', []));

        if (! is_array($candidate)
            || blank(data_get($candidate, 'slug'))
            || $inspectedProducts->contains($decision->selectedProductId)
            || $inspectedProducts->count() >= 3) {
            throw new UnexpectedValueException('Agent requested an invalid or repeated product inspection.');
        }

        $state['inspect_product_id'] = $decision->selectedProductId;
        $state['needs'][$currentIndex]['coverage_audit'] = $decision->audit->toArray();
        $this->transition($run, ['phase' => CartRunPhase::Inspecting, 'state' => $state], []);
    }

    /** @param array<string, mixed> $state */
    private function handleUnresolvedDecision(
        EventCartRun $run,
        array $state,
        int $currentIndex,
        array $need,
        CartAgentDecisionData $decision,
        GooseCartStatusService $statuses,
    ): void {
        $attemptsExhausted = count(data_get($need, 'attempts', [])) >= 6;
        $inspectedCandidates = count(data_get($need, 'inspected_products', [])) > 0;

        if (! $attemptsExhausted && ! $inspectedCandidates) {
            throw new UnexpectedValueException('Agent stopped before exhausting reasonable alternatives.');
        }

        $this->resolveUnmatched(
            $run,
            $state,
            $currentIndex,
            $need,
            $statuses,
            $decision->question ?? $decision->reason,
        );
    }

    /** @param array<string, mixed> $state */
    private function resolveUnmatched(
        EventCartRun $run,
        array $state,
        int $currentIndex,
        array $need,
        GooseCartStatusService $statuses,
        ?string $question = null,
    ): void {
        if ($run->mode === CartRunMode::Assisted && blank(data_get($need, 'human_answer'))) {
            $state['blocked_phase'] = CartRunPhase::Deciding->value;
            $this->transition(
                $run,
                [
                    'status' => CartRunStatus::WaitingForAnswer,
                    'state' => $state,
                    'blocker' => $question ?: 'Безпечного варіанта для «'.data_get($need, 'name').'» не знайшлося. Підкажіть заміну або дозвольте пропустити.',
                ],
                [['kind' => 'blocked', 'product' => (string) data_get($need, 'name')]],
                dispatchNext: false,
            );

            return;
        }

        $state['needs'][$currentIndex]['status'] = 'skipped';
        $state['needs'][$currentIndex]['skip_reason'] = $question ?: 'Безпечної альтернативи не знайдено.';
        $nextIndex = $this->nextUnresolvedNeedIndex($state['needs'], $currentIndex + 1);
        $state['current_need_index'] = $nextIndex ?? count($state['needs']);
        $nextPhase = $nextIndex !== null
            ? CartRunPhase::Searching
            : CartRunPhase::Auditing;
        $warnings = array_values(array_unique([
            ...($run->warnings ?? []),
            'Не знайдено: '.data_get($need, 'name').'.',
        ]));
        $this->transition(
            $run,
            ['phase' => $nextPhase, 'state' => $state, 'warnings' => $warnings],
            [['kind' => 'warning', 'product' => (string) data_get($need, 'name')]],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{kind: string, product?: string}>  $stepDefinitions
     */
    private function transition(
        EventCartRun $run,
        array $attributes,
        array $stepDefinitions,
        bool $dispatchNext = true,
        bool $commitNext = false,
    ): void {
        $updatedRun = DB::transaction(function () use ($run, $attributes, $stepDefinitions): ?EventCartRun {
            $lockedRun = EventCartRun::query()->lockForUpdate()->find($run->id);

            if (! $this->canAdvance($lockedRun)) {
                return null;
            }

            $lockedRun->update([
                ...$attributes,
                'cursor' => $lockedRun->cursor + 1,
            ]);
            $statuses = app(GooseCartStatusService::class);

            foreach ($stepDefinitions as $step) {
                $statuses->append(
                    $lockedRun,
                    $step['kind'],
                    $step['product'] ?? null,
                );
            }

            return $lockedRun;
        });

        if ($updatedRun === null || ! $dispatchNext) {
            return;
        }

        if ($commitNext) {
            CommitEventCartRunJob::dispatch($updatedRun->id, $updatedRun->cursor);

            return;
        }

        AdvanceEventCartRunJob::dispatch($updatedRun->id, $updatedRun->cursor);
    }

    private function finishWithoutProducts(
        EventCartRun $run,
        GooseCartStatusService $statuses,
        string $warning,
    ): void {
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
        $run->event()->update([
            'cart_sync_status' => CartSyncStatus::Stale,
            'cart_sync_error' => $run->error,
        ]);
    }

    private function canAdvance(?EventCartRun $run): bool
    {
        return $run !== null
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

    /** @param array<string, mixed> $product */
    private function candidate(array $product, SilpoCartContextData $cart): array
    {
        return [
            'product_id' => (string) data_get($product, 'id', data_get($product, 'productId', '')),
            'company_id' => (string) data_get($product, 'companyId', $cart->companyId),
            'branch_id' => (string) data_get($product, 'branchId', $cart->branchId),
            'external_product_id' => data_get($product, 'externalProductId'),
            'name' => (string) data_get($product, 'name', 'Товар Сільпо'),
            'slug' => data_get($product, 'slug'),
            'price' => (float) data_get($product, 'price', 0),
            'old_price' => data_get($product, 'oldPrice'),
            'stock' => (float) data_get($product, 'stock', 0),
            'available' => (bool) data_get($product, 'available', data_get($product, 'isAvailable', false)),
            'image' => data_get($product, 'image'),
            'weighted' => (bool) data_get($product, 'weighted', false),
            'step' => (float) data_get($product, 'step', data_get($product, 'addToBasketStep', 1)),
            'display_ratio' => data_get($product, 'displayRatio'),
            'special_prices' => data_get($product, 'specialPrices', []),
        ];
    }

    /**
     * @param  array<string, mixed>  $eventContext
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function agentEventContext(array $eventContext, array $plan): array
    {
        return [
            'summary' => data_get($eventContext, 'summary'),
            'people_count' => data_get($plan, 'serves'),
            'restrictions' => $this->foodConstraints($eventContext),
            'warnings' => data_get($eventContext, 'warnings', []),
        ];
    }

    /**
     * @param  array<string, mixed>  $eventContext
     * @return array<int, mixed>
     */
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

    /** @param array<string, mixed> $state */
    private function revisitNeed(
        EventCartRun $run,
        array $state,
        CartAgentAuditData $audit,
        CartQuantityCalculator $quantities,
    ): bool {
        if ($audit->revisitNeedKey === null || $audit->revisitQuery === null) {
            return false;
        }

        $needs = collect(data_get($state, 'needs', []));
        $needIndex = $needs->search(
            fn (array $need): bool => data_get($need, 'key') === $audit->revisitNeedKey,
        );

        if (! is_int($needIndex) || (int) data_get($state, 'audit_revisits', 0) >= 3) {
            return false;
        }

        $query = $quantities->normalizeSearchQuery($audit->revisitQuery);
        $attemptedQueries = collect(data_get($state, "needs.{$needIndex}.attempts", []))
            ->pluck('query')
            ->map(fn (string $attempt): string => Str::lower($attempt));

        if ($query === ''
            || $attemptedQueries->count() >= 6
            || $attemptedQueries->contains(Str::lower($query))) {
            return false;
        }

        $state['needs'][$needIndex]['status'] = 'pending';
        $state['needs'][$needIndex]['selected_item'] = null;
        $state['needs'][$needIndex]['search_query'] = $query;
        $state['current_need_index'] = $needIndex;
        $state['audit_revisits'] = (int) data_get($state, 'audit_revisits', 0) + 1;
        $stagedItems = collect($run->staged_items ?? [])
            ->reject(fn (array $item): bool => data_get($item, 'need_key') === $audit->revisitNeedKey)
            ->values()
            ->all();

        $this->transition(
            $run,
            [
                'phase' => CartRunPhase::Searching,
                'state' => $state,
                'staged_items' => $stagedItems,
                'estimated_total' => collect($stagedItems)->sum('estimated_total'),
            ],
            [
                ['kind' => 'auditing'],
                ['kind' => 'retry', 'product' => (string) data_get($state, "needs.{$needIndex}.name")],
            ],
        );

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $needs
     */
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
