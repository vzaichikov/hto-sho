<?php

namespace App\Jobs;

use App\CartProductEvidence;
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
use App\Services\CartCandidateSuitability;
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
        CartCandidateSuitability $candidateSuitability,
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
            CartRunPhase::Searching => $this->search($run, $silpo, $quantities, $statuses, $candidateSuitability),
            CartRunPhase::Inspecting => $this->inspect($run, $silpo, $statuses),
            CartRunPhase::Deciding => $this->decide($run, $agent, $quantities, $statuses, $candidateSuitability),
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
        CartCandidateSuitability $candidateSuitability,
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

        $assistedSearchPending = (bool) data_get($need, 'assisted_search_pending', false);
        $textSearchExhausted = count(data_get($need, 'attempts', [])) >= 6
            || (data_get($need, 'attempts', []) !== []
                && $candidateSuitability->nextSearchQuery($need) === null);

        if ($textSearchExhausted && ! $assistedSearchPending) {
            if ($this->browseCatalogScope(
                $run,
                $state,
                $currentIndex,
                $need,
                $silpo,
                $quantities,
                $statuses,
                $candidateSuitability,
            )) {
                return;
            }

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
            ->filter(fn (array $product): bool => $candidateSuitability->allows(
                $need,
                $product,
                data_get($state, 'event_context', []),
                data_get($state, 'plan_snapshot', []),
            ))
            ->filter(fn (array $product): bool => $this->hasAggregateStockCapacity(
                $need,
                $product,
                $run->staged_items ?? [],
                $quantities,
            ))
            ->reject(fn (array $product): bool => collect($run->staged_items ?? [])
                ->contains('product_id', $product['product_id'])
                && ! $candidateSuitability->allowsProductReuseForNeed($need, $product))
            ->sortBy(fn (array $product): array => $this->packageFitSortKey($need, $product, $quantities))
            ->values()
            ->all();
        $state['needs'][$currentIndex]['attempts'][] = [
            'query' => $query,
            'total_found' => count($candidates),
        ];
        $state['last_candidates'] = $candidates;
        $state['last_details'] = null;
        unset($state['needs'][$currentIndex]['assisted_search_pending']);
        $stepDefinitions = [['kind' => 'searching', 'product' => (string) data_get($need, 'name')]];

        if ($candidates === [] && count($state['needs'][$currentIndex]['attempts']) < 6) {
            $fallbackQuery = $candidateSuitability->nextSearchQuery($state['needs'][$currentIndex]);

            if ($fallbackQuery !== null) {
                $state['needs'][$currentIndex]['search_query'] = $fallbackQuery;
                $this->transition(
                    $run,
                    ['phase' => CartRunPhase::Searching, 'state' => $state],
                    [...$stepDefinitions, ['kind' => 'retry', 'product' => (string) data_get($need, 'name')]],
                );

                return;
            }
        }

        if ($candidates === []
            && $candidateSuitability->nextSearchQuery($state['needs'][$currentIndex]) === null
            && $candidateSuitability->nextCatalogScope(
                $state['needs'][$currentIndex],
                data_get($state, 'catalog_scopes', []),
            ) !== null) {
            $this->transition(
                $run,
                ['phase' => CartRunPhase::Searching, 'state' => $state],
                [...$stepDefinitions, ['kind' => 'retry', 'product' => (string) data_get($need, 'name')]],
            );

            return;
        }

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

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $need
     */
    private function browseCatalogScope(
        EventCartRun $run,
        array $state,
        int $currentIndex,
        array $need,
        SilpoCartGateway $silpo,
        CartQuantityCalculator $quantities,
        GooseCartStatusService $statuses,
        CartCandidateSuitability $candidateSuitability,
    ): bool {
        if (count(data_get($need, 'browse_attempts', [])) >= (int) config('silpo_catalog_search.max_catalog_scope_attempts', 5)) {
            return false;
        }

        $scope = $candidateSuitability->nextCatalogScope(
            $need,
            data_get($state, 'catalog_scopes', []),
        );

        if (! is_array($scope)) {
            return false;
        }

        $cart = SilpoCartContextData::fromRunContext($run->cart_context);
        $products = $silpo->browseProducts(
            $this->accessToken($run),
            $cart,
            (string) data_get($scope, 'type'),
            (string) data_get($scope, 'slug'),
            (int) config('silpo_catalog_search.catalog_scope_product_limit', 20),
            harnessRun: $run->harnessRun,
        );
        $candidates = collect($products)
            ->map(function (array $product) use ($cart, $scope): array {
                $candidate = $this->candidate($product, $cart);
                $candidate['catalog_scope'] = [
                    'type' => (string) data_get($scope, 'type'),
                    'slug' => (string) data_get($scope, 'slug'),
                    'label' => data_get($scope, 'label'),
                    'matched' => true,
                ];

                return $candidate;
            })
            ->filter(fn (array $product): bool => $product['product_id'] !== '')
            ->filter(fn (array $product): bool => $candidateSuitability->allows(
                $need,
                $product,
                data_get($state, 'event_context', []),
                data_get($state, 'plan_snapshot', []),
            ))
            ->filter(fn (array $product): bool => $this->hasAggregateStockCapacity(
                $need,
                $product,
                $run->staged_items ?? [],
                $quantities,
            ))
            ->reject(fn (array $product): bool => collect($run->staged_items ?? [])
                ->contains('product_id', $product['product_id'])
                && ! $candidateSuitability->allowsProductReuseForNeed($need, $product))
            ->sortBy(fn (array $product): array => $this->packageFitSortKey($need, $product, $quantities))
            ->values()
            ->all();
        $state['needs'][$currentIndex]['browse_attempts'][] = [
            'type' => (string) data_get($scope, 'type'),
            'slug' => (string) data_get($scope, 'slug'),
            'total_found' => count($candidates),
        ];
        $state['last_candidates'] = $candidates;
        $state['last_details'] = null;
        $nextPhase = $candidates === [] ? CartRunPhase::Searching : CartRunPhase::Deciding;
        $steps = [['kind' => 'searching', 'product' => (string) data_get($need, 'name')]];

        if ($candidates === []) {
            $steps[] = ['kind' => 'retry', 'product' => (string) data_get($need, 'name')];
        }

        $this->transition(
            $run,
            ['phase' => $nextPhase, 'state' => $state],
            $steps,
        );

        return true;
    }

    /** @param array<string, mixed> $need @param array<string, mixed> $product */
    private function packageFitSortKey(
        array $need,
        array $product,
        CartQuantityCalculator $quantities,
    ): array {
        $overage = $quantities->packageOverageInBaseUnits($need, $product);
        $estimatedTotal = INF;

        try {
            $quantity = $quantities->quantityFor(
                $need,
                $product,
                (float) data_get($need, 'quantity', 1),
            );
            $estimatedTotal = $quantities->estimatedTotal($product, $quantity);
        } catch (Throwable) {
            // Invalid quantities remain last and will be rejected by normal validation.
        }

        return [$overage === null ? 1 : 0, $overage ?? 0, $estimatedTotal];
    }

    /**
     * @param  array<string, mixed>  $need
     * @param  array<string, mixed>  $product
     * @param  array<int, array<string, mixed>>  $stagedItems
     */
    private function hasAggregateStockCapacity(
        array $need,
        array $product,
        array $stagedItems,
        CartQuantityCalculator $quantities,
    ): bool {
        if (! is_numeric(data_get($product, 'stock'))) {
            return true;
        }

        try {
            $neededQuantity = $quantities->quantityFor(
                $need,
                $product,
                (float) data_get($need, 'quantity', 1),
            );
        } catch (Throwable) {
            return false;
        }

        $alreadyStaged = collect($stagedItems)
            ->where('product_id', data_get($product, 'product_id'))
            ->sum('quantity');

        return ((float) $alreadyStaged + $neededQuantity) <= ((float) data_get($product, 'stock') + 0.0001);
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
        CartCandidateSuitability $candidateSuitability,
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
            'product_constraints' => $this->productConstraints(
                data_get($state, 'event_context', []),
                data_get($state, 'plan_snapshot', []),
            ),
            'current_need' => $need,
            'all_needs' => collect(data_get($state, 'needs', []))->map(fn (array $item): array => Arr::only($item, [
                'key', 'name', 'quantity', 'unit', 'status', 'selected_item',
            ]))->all(),
            'candidates' => data_get($state, 'last_candidates', []),
            'inspected_details' => data_get($state, 'last_details'),
            'staged_items' => $run->staged_items ?? [],
        ], $run->harnessRun);
        $this->guardDecisionAuditKeys($decision->audit, data_get($state, 'needs', []));

        match ($decision->action) {
            'select' => $this->selectOrInspectCandidate(
                $run,
                $state,
                $currentIndex,
                $need,
                $decision,
                $quantities,
                $statuses,
                $candidateSuitability,
            ),
            'retry' => $this->retrySearch(
                $run,
                $state,
                $currentIndex,
                $need,
                $decision,
                $quantities,
                $statuses,
                $candidateSuitability,
            ),
            'inspect' => $this->inspectCandidate($run, $state, $currentIndex, $need, $decision),
            'skip', 'ask' => $this->handleUnresolvedDecision(
                $run,
                $state,
                $currentIndex,
                $need,
                $decision,
                $quantities,
                $statuses,
                $candidateSuitability,
            ),
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
        $remainingNeeds = collect(data_get($state, 'needs', []))->reject(
            fn (array $need): bool => data_get($need, 'status') === 'selected',
        );

        if ($remainingNeeds->isEmpty()) {
            $audit = $this->normalizeCompletedAudit(data_get($state, 'needs', []));
        }

        $state['final_audit'] = $audit->toArray();
        $hasHumanAnswer = filled(data_get($state, 'audit_answer'));
        unset($state['audit_answer']);
        $hasGap = $remainingNeeds->isNotEmpty();
        $auditRequestsRevisit = $hasGap
            && ! $audit->complete
            && $audit->revisitNeedKey !== null
            && $audit->revisitQuery !== null;

        if (($hasGap || $auditRequestsRevisit)
            && $this->revisitNeed($run, $state, $audit, $quantities)) {
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

        $evidenceWarnings = collect($run->staged_items ?? [])
            ->pluck('review_note')
            ->filter(fn (mixed $note): bool => is_string($note) && filled($note))
            ->values()
            ->all();
        $state['has_unmet_needs'] = $hasGap;
        $this->transition(
            $run,
            [
                'status' => CartRunStatus::WaitingForConfirmation,
                'phase' => CartRunPhase::ReadyToCommit,
                'state' => $state,
                'warnings' => array_values(array_unique([
                    ...($run->warnings ?? []),
                    ...$evidenceWarnings,
                    ...$audit->warnings,
                ])),
            ],
            [['kind' => 'auditing']],
            dispatchNext: false,
        );
    }

    /** @param array<int, array<string, mixed>> $needs */
    private function normalizeCompletedAudit(array $needs): CartAgentAuditData
    {
        $coveredNeedKeys = collect($needs)
            ->pluck('key')
            ->filter(fn (mixed $key): bool => is_string($key))
            ->values()
            ->all();

        return new CartAgentAuditData(
            complete: true,
            coveredNeedKeys: $coveredNeedKeys,
            remainingNeedKeys: [],
            enoughForPeople: true,
            warnings: [],
            revisitNeedKey: null,
            revisitQuery: null,
            question: null,
        );
    }

    /** @param array<string, mixed> $state */
    private function selectOrInspectCandidate(
        EventCartRun $run,
        array $state,
        int $currentIndex,
        array $need,
        CartAgentDecisionData $decision,
        CartQuantityCalculator $quantities,
        GooseCartStatusService $statuses,
        CartCandidateSuitability $candidateSuitability,
    ): void {
        $hasCurrentDetails = data_get($state, 'last_details.product_id') === $decision->selectedProductId;

        if ($candidateSuitability->requiresInspection($need, data_get($state, 'event_context', []))
            && ! $hasCurrentDetails) {
            $inspectedProducts = collect(data_get($need, 'inspected_products', []));

            if ($inspectedProducts->count() >= 3
                || $inspectedProducts->contains($decision->selectedProductId)) {
                $this->selectCandidate(
                    $run,
                    $state,
                    $currentIndex,
                    $need,
                    $decision,
                    $quantities,
                    $statuses,
                    $candidateSuitability,
                );

                return;
            }

            $this->inspectCandidate($run, $state, $currentIndex, $need, $decision);

            return;
        }

        $this->selectCandidate(
            $run,
            $state,
            $currentIndex,
            $need,
            $decision,
            $quantities,
            $statuses,
            $candidateSuitability,
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
        GooseCartStatusService $statuses,
        CartCandidateSuitability $candidateSuitability,
    ): void {
        $candidate = collect(data_get($state, 'last_candidates', []))
            ->firstWhere('product_id', $decision->selectedProductId);

        if (! is_array($candidate)) {
            $invalidDecisionCount = (int) data_get($need, 'invalid_decision_count', 0) + 1;
            $state['needs'][$currentIndex]['invalid_decision_count'] = $invalidDecisionCount;

            if ($invalidDecisionCount < 2 && data_get($state, 'last_candidates', []) !== []) {
                $this->transition(
                    $run,
                    ['phase' => CartRunPhase::Deciding, 'state' => $state],
                    [['kind' => 'retry', 'product' => (string) data_get($need, 'name')]],
                );

                return;
            }

            if ($invalidDecisionCount >= 2 && $this->selectCatalogFallback(
                $run,
                $state,
                $currentIndex,
                $need,
                $decision,
                $quantities,
                $statuses,
                $candidateSuitability,
            )) {
                return;
            }

            $fallbackQuery = $candidateSuitability->nextSearchQuery(
                data_get($state, "needs.{$currentIndex}", $need),
            );

            if ($fallbackQuery !== null) {
                $state['needs'][$currentIndex]['search_query'] = $fallbackQuery;
                $state['last_candidates'] = [];
                $state['last_details'] = null;
                $this->transition(
                    $run,
                    ['phase' => CartRunPhase::Searching, 'state' => $state],
                    [['kind' => 'retry', 'product' => (string) data_get($need, 'name')]],
                );

                return;
            }

            if ($this->transitionToNextCatalogScopeIfAvailable(
                $run,
                $state,
                $currentIndex,
                $need,
                $candidateSuitability,
            )) {
                return;
            }

            $this->resolveUnmatched(
                $run,
                $state,
                $currentIndex,
                $need,
                $statuses,
                'Модель не змогла вибрати товар із безпечного відфільтрованого набору.',
            );

            return;
        }

        $preferredCandidate = collect(data_get($state, 'last_candidates', []))->first();

        if (is_array($preferredCandidate)
            && data_get($preferredCandidate, 'catalog_scope.type') === 'category'
            && ! $candidateSuitability->requiresInspection($need, data_get($state, 'event_context', []))
            && ($this->packageFitSortKey($need, $preferredCandidate, $quantities)
                <=> $this->packageFitSortKey($need, $candidate, $quantities)) < 0) {
            $candidate = $preferredCandidate;
        }

        $candidateForValidation = $candidate;

        if (data_get($state, 'last_details.product_id') === $decision->selectedProductId) {
            $candidateForValidation['details'] = data_get($state, 'last_details');
        }

        $evidence = $candidateSuitability->evidence(
            $need,
            $candidateForValidation,
            data_get($state, 'event_context', []),
            data_get($state, 'plan_snapshot', []),
        );

        if (! $evidence['selectable']) {
            $state['last_candidates'] = collect(data_get($state, 'last_candidates', []))
                ->reject(fn (array $item): bool => data_get($item, 'product_id') === $decision->selectedProductId)
                ->values()
                ->all();
            $state['last_details'] = null;

            if ($state['last_candidates'] !== []) {
                $this->transition(
                    $run,
                    ['phase' => CartRunPhase::Deciding, 'state' => $state],
                    [['kind' => 'warning', 'product' => (string) data_get($candidate, 'name')]],
                );

                return;
            }

            $fallbackQuery = $candidateSuitability->nextSearchQuery($need);

            if ($fallbackQuery !== null) {
                $state['needs'][$currentIndex]['search_query'] = $fallbackQuery;
                $this->transition(
                    $run,
                    ['phase' => CartRunPhase::Searching, 'state' => $state],
                    [['kind' => 'retry', 'product' => (string) data_get($need, 'name')]],
                );

                return;
            }

            if ($this->transitionToNextCatalogScopeIfAvailable(
                $run,
                $state,
                $currentIndex,
                $need,
                $candidateSuitability,
            )) {
                return;
            }

            $this->resolveUnmatched(
                $run,
                $state,
                $currentIndex,
                $need,
                $statuses,
                'Перевірені дані товару не підтвердили обовʼязкові обмеження безпеки.',
            );

            return;
        }

        $quantity = $quantities->quantityFor($need, $candidate, (float) $decision->quantity);
        $reviewNote = collect([
            $evidence['review_note'],
            $quantities->packageRoundingNote($need, $candidate, $quantity),
        ])->filter(fn (mixed $note): bool => is_string($note) && filled($note))->implode(' ');
        $selectedItem = [
            ...$candidate,
            'need_key' => data_get($need, 'key'),
            'need_name' => data_get($need, 'name'),
            'quantity' => $quantity,
            'estimated_total' => $quantities->estimatedTotal($candidate, $quantity),
            'match_evidence' => $evidence['match'],
            'safety_evidence' => $evidence['safety'],
            'selection_explanation' => $this->selectionExplanation($need, $candidate, $evidence),
            'review_note' => $reviewNote !== '' ? $reviewNote : null,
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

    /**
     * @param  array<string, mixed>  $need
     * @param  array<string, mixed>  $candidate
     * @param  array{selectable: bool, match: string, safety: string, review_note: ?string}  $evidence
     */
    private function selectionExplanation(array $need, array $candidate, array $evidence): string
    {
        $product = '«'.data_get($candidate, 'name').'»';
        $needName = '«'.data_get($need, 'name').'»';

        if ($evidence['match'] === CartProductEvidence::MATCH_SAME_ROLE) {
            return "{$product} — найближча доступна рольова заміна для {$needName}; точного товару після обмежених пошуків не знайдено.";
        }

        return "{$product} вибрано для {$needName}: товар відповідає потрібній ролі та пройшов перевірки доступності й відомих заборон.";
    }

    /** @param array<string, mixed> $state */
    private function retrySearch(
        EventCartRun $run,
        array $state,
        int $currentIndex,
        array $need,
        CartAgentDecisionData $decision,
        CartQuantityCalculator $quantities,
        GooseCartStatusService $statuses,
        CartCandidateSuitability $candidateSuitability,
    ): void {
        if ($this->selectCatalogFallback(
            $run,
            $state,
            $currentIndex,
            $need,
            $decision,
            $quantities,
            $statuses,
            $candidateSuitability,
        )) {
            return;
        }

        $query = $quantities->normalizeSearchQuery((string) $decision->query);
        $attemptedQueries = collect(data_get($need, 'attempts', []))->pluck('query');

        if ($query === '' || $attemptedQueries->contains(fn (string $attempt): bool => Str::lower($attempt) === Str::lower($query))) {
            $query = $candidateSuitability->nextSearchQuery($need) ?? '';

            if ($query === '') {
                if ($this->transitionToNextCatalogScopeIfAvailable(
                    $run,
                    $state,
                    $currentIndex,
                    $need,
                    $candidateSuitability,
                )) {
                    return;
                }

                $this->resolveUnmatched(
                    $run,
                    $state,
                    $currentIndex,
                    $need,
                    $statuses,
                    $decision->question ?? $decision->reason,
                );

                return;
            }
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
        CartQuantityCalculator $quantities,
        GooseCartStatusService $statuses,
        CartCandidateSuitability $candidateSuitability,
    ): void {
        if ($this->selectCatalogFallback(
            $run,
            $state,
            $currentIndex,
            $need,
            $decision,
            $quantities,
            $statuses,
            $candidateSuitability,
        )) {
            return;
        }

        $attemptsExhausted = count(data_get($need, 'attempts', [])) >= 6;
        $inspectedCandidates = count(data_get($need, 'inspected_products', [])) > 0;

        if (! $attemptsExhausted && ! $inspectedCandidates) {
            $fallbackQuery = $candidateSuitability->nextSearchQuery($need);

            if ($fallbackQuery !== null) {
                $state['needs'][$currentIndex]['search_query'] = $fallbackQuery;
                $state['needs'][$currentIndex]['coverage_audit'] = $decision->audit->toArray();
                $this->transition(
                    $run,
                    ['phase' => CartRunPhase::Searching, 'state' => $state],
                    [['kind' => 'retry', 'product' => (string) data_get($need, 'name')]],
                );

                return;
            }
        }

        if ($this->transitionToNextCatalogScopeIfAvailable(
            $run,
            $state,
            $currentIndex,
            $need,
            $candidateSuitability,
        )) {
            return;
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

    /** @param array<string, mixed> $state @param array<string, mixed> $need */
    private function selectCatalogFallback(
        EventCartRun $run,
        array $state,
        int $currentIndex,
        array $need,
        CartAgentDecisionData $decision,
        CartQuantityCalculator $quantities,
        GooseCartStatusService $statuses,
        CartCandidateSuitability $candidateSuitability,
    ): bool {
        $candidate = collect(data_get($state, 'last_candidates', []))->first();

        if (! is_array($candidate)
            || data_get($need, 'browse_attempts', []) === []
            || $candidateSuitability->requiresInspection(
                $need,
                data_get($state, 'event_context', []),
            )) {
            return false;
        }

        $fallbackDecision = new CartAgentDecisionData(
            action: 'select',
            selectedProductId: (string) data_get($candidate, 'product_id'),
            query: null,
            quantity: (float) data_get($need, 'quantity', 1),
            reason: 'Детермінована рольова заміна з відфільтрованої області каталогу.',
            question: null,
            audit: $decision->audit,
        );
        $this->selectCandidate(
            $run,
            $state,
            $currentIndex,
            $need,
            $fallbackDecision,
            $quantities,
            $statuses,
            $candidateSuitability,
        );

        return true;
    }

    /** @param array<string, mixed> $state */
    private function transitionToNextCatalogScopeIfAvailable(
        EventCartRun $run,
        array $state,
        int $currentIndex,
        array $need,
        CartCandidateSuitability $candidateSuitability,
    ): bool {
        $currentNeed = data_get($state, "needs.{$currentIndex}", $need);

        if (! is_array($currentNeed)
            || count(data_get($currentNeed, 'browse_attempts', [])) >= (int) config('silpo_catalog_search.max_catalog_scope_attempts', 5)
            || $candidateSuitability->nextCatalogScope(
                $currentNeed,
                data_get($state, 'catalog_scopes', []),
            ) === null) {
            return false;
        }

        $state['last_candidates'] = [];
        $state['last_details'] = null;
        $this->transition(
            $run,
            ['phase' => CartRunPhase::Searching, 'state' => $state],
            [['kind' => 'retry', 'product' => (string) data_get($currentNeed, 'name')]],
        );

        return true;
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

    /**
     * @param  array<string, mixed>  $eventContext
     * @param  array<string, mixed>  $plan
     * @return array<int, string>
     */
    private function productConstraints(array $eventContext, array $plan): array
    {
        $agreementConstraints = collect(data_get($eventContext, 'agreements', []))
            ->filter(fn (mixed $agreement): bool => is_array($agreement))
            ->pluck('summary')
            ->filter(fn (mixed $summary): bool => is_string($summary)
                && Str::contains(Str::lower($summary), [
                    'без ',
                    'не куп',
                    'лише сертифікован',
                    'не вжив',
                    'не їсть',
                ]));

        return $agreementConstraints
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
    private function guardDecisionAuditKeys(CartAgentAuditData $audit, array $needs): void
    {
        $knownKeys = collect($needs)->pluck('key');
        $reportedKeys = collect([...$audit->coveredNeedKeys, ...$audit->remainingNeedKeys]);
        $duplicatedKeys = collect($audit->coveredNeedKeys)->intersect($audit->remainingNeedKeys);

        if ($reportedKeys->diff($knownKeys)->isNotEmpty() || $duplicatedKeys->isNotEmpty()) {
            throw new UnexpectedValueException('Agent decision audit contains invalid need keys.');
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
