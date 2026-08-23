<?php

namespace Tests\Feature;

use App\CartRunMode;
use App\CartRunPhase;
use App\CartRunStatus;
use App\CartSyncStatus;
use App\Contracts\CartProductAgent;
use App\Contracts\SilpoCartGateway;
use App\Data\CartAgentAuditData;
use App\Data\CartAgentDecisionData;
use App\Data\CartAgentPreparationData;
use App\Data\SilpoCartContextData;
use App\Jobs\AdvanceEventCartRunJob;
use App\Jobs\CommitEventCartRunJob;
use App\Models\Event;
use App\Models\EventCartRun;
use App\Models\HarnessRun;
use App\Models\SilpoConnection;
use App\Models\User;
use App\Services\CartQuantityCalculator;
use App\Services\GooseCartStatusService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EventCartRunTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_missing_cart_stops_before_a_run_and_tells_the_user_to_prepare_silpo(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $this->app->instance(SilpoCartGateway::class, new FakeCartGateway(null));

        $this->actingAs($owner)
            ->getJson(route('events.silpo.cart-preflight', $event))
            ->assertConflict()
            ->assertJsonPath('code', 'cart_missing')
            ->assertJsonPath(
                'message',
                'Гусь без маршруту загубиться. Зайдіть у Сільпо, створіть кошик та оберіть адресу доставки і спосіб отримання.',
            );

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.store', $event), ['mode' => 'assisted'])
            ->assertConflict()
            ->assertJsonPath('code', 'cart_missing');

        $this->assertDatabaseMissing('event_cart_runs', ['event_id' => $event->id]);
        Queue::assertNothingPushed();
    }

    public function test_ready_cart_starts_a_persisted_background_run(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $this->app->instance(SilpoCartGateway::class, new FakeCartGateway($cart));

        $this->actingAs($owner)
            ->getJson(route('events.silpo.cart-preflight', $event))
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('cart.delivery_label', 'Доставка Сільпо')
            ->assertJsonPath('cart.items_count', 1);

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.store', $event), ['mode' => 'assisted'])
            ->assertAccepted()
            ->assertJsonStructure(['run_url']);

        $run = EventCartRun::query()->sole();
        $this->assertSame(CartRunStatus::Running, $run->status);
        $this->assertSame(CartRunPhase::Preparing, $run->phase);
        $this->assertSame($event->state_version, $run->plan_state_version);
        $this->assertCount(2, $run->steps);
        $this->assertSame(CartSyncStatus::Syncing, $event->refresh()->cart_sync_status);
        Queue::assertPushed(
            AdvanceEventCartRunJob::class,
            fn (AdvanceEventCartRunJob $job): bool => $job->runId === $run->id && $job->expectedCursor === 0,
        );
    }

    public function test_single_product_flow_stages_then_writes_one_absolute_batch_and_preserves_other_items(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $gateway = new FakeCartGateway($this->readyCart());
        $gateway->searchResults['вода питна'] = [$this->waterProduct()];
        $agent = new FakeCartAgent(
            preparation: new CartAgentPreparationData([$this->waterNeed()]),
            decisions: [new CartAgentDecisionData(
                action: 'select',
                selectedProductId: 'water-1',
                query: null,
                quantity: 2,
                reason: 'Безпечний і доступний варіант.',
                question: null,
                audit: $this->audit(covered: ['water'], remaining: [], complete: true),
            )],
            audit: $this->audit(covered: ['water'], remaining: [], complete: true),
        );
        $this->app->instance(SilpoCartGateway::class, $gateway);

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.store', $event), ['mode' => 'auto'])
            ->assertAccepted();

        $run = EventCartRun::query()->sole();
        $quantities = new CartQuantityCalculator;
        $statuses = new GooseCartStatusService;

        (new AdvanceEventCartRunJob($run->id, 0))->handle($agent, $gateway, $quantities, $statuses);
        $run->refresh();
        $this->assertSame(CartRunPhase::Searching, $run->phase);

        (new AdvanceEventCartRunJob($run->id, $run->cursor))->handle($agent, $gateway, $quantities, $statuses);
        $run->refresh();
        $this->assertSame(['вода питна'], $gateway->searchQueries);
        $this->assertSame(CartRunPhase::Deciding, $run->phase);

        (new AdvanceEventCartRunJob($run->id, $run->cursor))->handle($agent, $gateway, $quantities, $statuses);
        $run->refresh();
        $this->assertSame(CartRunPhase::Auditing, $run->phase);
        $this->assertSame(2.0, (float) $run->staged_items[0]['quantity']);

        (new AdvanceEventCartRunJob($run->id, $run->cursor))->handle($agent, $gateway, $quantities, $statuses);
        $run->refresh();
        $this->assertSame(CartRunPhase::ReadyToCommit, $run->phase);
        Queue::assertPushed(CommitEventCartRunJob::class);

        (new CommitEventCartRunJob($run->id, $run->cursor))->handle($gateway, $statuses);
        $run->refresh();

        $this->assertSame(CartRunStatus::Synced, $run->status);
        $this->assertSame(CartSyncStatus::Synced, $event->refresh()->cart_sync_status);
        $this->assertCount(1, $gateway->writes);
        $this->assertCount(1, $gateway->writes[0]);
        $this->assertSame('water-1', $gateway->writes[0][0]['productId']);
        $this->assertSame(2.0, (float) $gateway->writes[0][0]['quantity']);
        $this->assertFalse($gateway->writes[0][0]['addQuantity']);
        $this->assertContains('existing-1', collect($gateway->cart?->items)->pluck('product_id')->all());
    }

    public function test_assisted_mode_waits_only_after_all_search_attempts_are_exhausted(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $run = $this->exhaustedRun($event, $cart, CartRunMode::Assisted);
        $gateway = new FakeCartGateway($cart);

        (new AdvanceEventCartRunJob($run->id, 0))->handle(
            new FakeCartAgent(new CartAgentPreparationData([]), [], $this->audit([], ['water'], false)),
            $gateway,
            new CartQuantityCalculator,
            new GooseCartStatusService,
        );

        $run->refresh();
        $this->assertSame(CartRunStatus::WaitingForAnswer, $run->status);
        $this->assertNotNull($run->blocker);
        $this->assertSame([], $gateway->searchQueries);
        Queue::assertNotPushed(CommitEventCartRunJob::class);
    }

    public function test_auto_mode_skips_an_exhausted_need_without_asking_a_question(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $run = $this->exhaustedRun($event, $cart, CartRunMode::Auto);
        $gateway = new FakeCartGateway($cart);

        (new AdvanceEventCartRunJob($run->id, 0))->handle(
            new FakeCartAgent(new CartAgentPreparationData([]), [], $this->audit([], ['water'], false)),
            $gateway,
            new CartQuantityCalculator,
            new GooseCartStatusService,
        );

        $run->refresh();
        $this->assertSame(CartRunStatus::Running, $run->status);
        $this->assertSame(CartRunPhase::Auditing, $run->phase);
        $this->assertSame('skipped', data_get($run->state, 'needs.0.status'));
        $this->assertNull($run->blocker);
        $this->assertSame(['Не знайдено: Вода питна.'], $run->warnings);
    }

    public function test_final_audit_can_reopen_one_need_with_a_new_query_before_commit(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $need = $this->waterNeed();
        $need['status'] = 'selected';
        $need['attempts'] = [['query' => 'вода питна', 'total_found' => 1]];
        $need['selected_item'] = ['need_key' => 'water', 'product_id' => 'water-1'];
        $run = EventCartRun::factory()->for($event)->create([
            'mode' => CartRunMode::Auto,
            'phase' => CartRunPhase::Auditing,
            'status' => CartRunStatus::Running,
            'cursor' => 0,
            'cart_id' => $cart->cartId,
            'delivery_fingerprint' => $cart->fingerprint(),
            'cart_context' => $cart->toRunContext(),
            'state' => [
                'event_context' => $event->state,
                'plan_snapshot' => $event->shopping_plan,
                'needs' => [$need],
                'current_need_index' => 1,
            ],
            'staged_items' => [[
                'need_key' => 'water',
                'product_id' => 'water-1',
                'estimated_total' => 60,
            ]],
        ]);
        $audit = new CartAgentAuditData(
            complete: false,
            coveredNeedKeys: [],
            remainingNeedKeys: ['water'],
            enoughForPeople: false,
            warnings: [],
            revisitNeedKey: 'water',
            revisitQuery: 'мінеральна вода',
            question: null,
        );

        (new AdvanceEventCartRunJob($run->id, 0))->handle(
            new FakeCartAgent(new CartAgentPreparationData([]), [], $audit),
            new FakeCartGateway($cart),
            new CartQuantityCalculator,
            new GooseCartStatusService,
        );

        $run->refresh();
        $this->assertSame(CartRunPhase::Searching, $run->phase);
        $this->assertSame('pending', data_get($run->state, 'needs.0.status'));
        $this->assertSame('мінеральна вода', data_get($run->state, 'needs.0.search_query'));
        $this->assertSame(1, data_get($run->state, 'audit_revisits'));
        $this->assertSame([], $run->staged_items);
        Queue::assertPushed(AdvanceEventCartRunJob::class);
        Queue::assertNotPushed(CommitEventCartRunJob::class);
    }

    public function test_commit_refuses_to_write_when_the_silpo_delivery_route_changed(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $originalCart = $this->readyCart();
        $changedCart = new SilpoCartContextData(
            cartId: $originalCart->cartId,
            deliveryType: $originalCart->deliveryType,
            branchId: 'another-branch',
            companyId: $originalCart->companyId,
            slotStart: $originalCart->slotStart,
            slotEnd: $originalCart->slotEnd,
            items: $originalCart->items,
            validations: [],
            slot: $originalCart->slot,
            totalAfterDiscounts: $originalCart->totalAfterDiscounts,
        );
        $run = EventCartRun::factory()->for($event)->create([
            'phase' => CartRunPhase::ReadyToCommit,
            'status' => CartRunStatus::Running,
            'cursor' => 0,
            'cart_id' => $originalCart->cartId,
            'delivery_fingerprint' => $originalCart->fingerprint(),
            'cart_context' => $originalCart->toRunContext(),
            'state' => ['has_unmet_needs' => false],
            'staged_items' => [[
                'need_key' => 'water',
                'product_id' => 'water-1',
                'company_id' => 'company-1',
                'branch_id' => 'branch-1',
                'name' => 'Вода негазована 2 л',
                'quantity' => 2,
                'price' => 30,
                'step' => 1,
                'stock' => 20,
            ]],
        ]);
        $gateway = new FakeCartGateway($changedCart);

        (new CommitEventCartRunJob($run->id, 0))->handle($gateway, new GooseCartStatusService);

        $run->refresh();
        $this->assertSame(CartRunStatus::Stale, $run->status);
        $this->assertSame(CartSyncStatus::Stale, $event->refresh()->cart_sync_status);
        $this->assertSame([], $gateway->writes);
    }

    public function test_event_page_contains_the_large_live_cart_workspace(): void
    {
        [$owner, $event] = $this->eventWithPlan();

        $this->actingAs($owner)
            ->get(route('events.show', ['event' => $event, 'tab' => 'plan']))
            ->assertOk()
            ->assertSee('Збираємо справжній кошик')
            ->assertSee('data-silpo-dialog', false)
            ->assertSee('data-silpo-steps', false)
            ->assertSee('Тимчасовий кошик')
            ->assertSee('Повний автопілот');
    }

    public function test_cart_run_endpoints_are_owner_scoped_and_runs_cannot_cross_events(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        $stranger = User::factory()->create();
        $otherEvent = Event::factory()->ready()->for($owner)->create();
        $cart = $this->readyCart();
        $run = EventCartRun::factory()->for($event)->create([
            'cart_id' => $cart->cartId,
            'delivery_fingerprint' => $cart->fingerprint(),
            'cart_context' => $cart->toRunContext(),
        ]);

        $this->actingAs($stranger)
            ->getJson(route('events.silpo.cart-preflight', $event))
            ->assertForbidden();
        $this->actingAs($stranger)
            ->postJson(route('events.cart-runs.store', $event), ['mode' => 'auto'])
            ->assertForbidden();
        $this->actingAs($owner)
            ->getJson(route('events.cart-runs.show', [$otherEvent, $run]))
            ->assertNotFound();
    }

    /** @return array{User, Event} */
    private function eventWithPlan(): array
    {
        $owner = User::factory()->create();
        $event = Event::factory()->ready()->for($owner)->create([
            'people_count' => 4,
            'shopping_plan' => [
                'summary' => 'Вода для чотирьох людей.',
                'serves' => 4,
                'items' => [[
                    'name' => 'Вода питна',
                    'category' => 'water',
                    'quantity' => 4,
                    'unit' => 'л',
                    'note' => 'По літру на людину.',
                ]],
                'warnings' => [],
                'unanswered_question_keys' => [],
            ],
        ]);

        return [$owner, $event];
    }

    private function readyCart(): SilpoCartContextData
    {
        return new SilpoCartContextData(
            cartId: 'cart-1',
            deliveryType: 'DeliveryHome',
            branchId: 'branch-1',
            companyId: 'company-1',
            slotStart: now()->addDay()->startOfHour()->toISOString(),
            slotEnd: now()->addDay()->startOfHour()->addHour()->toISOString(),
            items: [[
                'product_id' => 'existing-1',
                'company_id' => 'company-1',
                'branch_id' => 'branch-1',
                'name' => 'Серветки',
                'quantity' => 1,
                'price' => 25,
                'total' => 25,
                'step' => 1,
                'stock' => 20,
                'source' => 'existing',
            ]],
            validations: [],
            slot: [
                'start' => now()->addDay()->startOfHour()->toISOString(),
                'end' => now()->addDay()->startOfHour()->addHour()->toISOString(),
                'deliveryCost' => 69,
                'minOrderCost' => 500,
            ],
            totalAfterDiscounts: 25,
        );
    }

    /** @return array<string, mixed> */
    private function waterNeed(): array
    {
        return [
            'key' => 'water',
            'source_index' => 0,
            'name' => 'Вода питна',
            'category' => 'water',
            'quantity' => 4.0,
            'unit' => 'л',
            'note' => 'По літру на людину.',
            'search_query' => 'вода питна',
            'status' => 'pending',
            'attempts' => [],
            'inspected_products' => [],
            'selected_item' => null,
            'human_answer' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function waterProduct(): array
    {
        return [
            'id' => 'water-1',
            'companyId' => 'company-1',
            'branchId' => 'branch-1',
            'name' => 'Вода негазована 2 л',
            'slug' => 'water-2l',
            'price' => 30,
            'stock' => 20,
            'available' => true,
            'weighted' => false,
            'step' => 1,
            'displayRatio' => '2 л',
        ];
    }

    /** @param array<int, string> $covered @param array<int, string> $remaining */
    private function audit(array $covered, array $remaining, bool $complete): CartAgentAuditData
    {
        return new CartAgentAuditData(
            complete: $complete,
            coveredNeedKeys: $covered,
            remainingNeedKeys: $remaining,
            enoughForPeople: $complete,
            warnings: [],
            revisitNeedKey: null,
            revisitQuery: null,
            question: $complete ? null : 'Чим замінити воду?',
        );
    }

    private function exhaustedRun(Event $event, SilpoCartContextData $cart, CartRunMode $mode): EventCartRun
    {
        $need = $this->waterNeed();
        $need['attempts'] = collect(range(1, 6))
            ->map(fn (int $attempt): array => ['query' => "вода {$attempt}", 'total_found' => 0])
            ->all();

        return EventCartRun::factory()->for($event)->create([
            'mode' => $mode,
            'phase' => CartRunPhase::Searching,
            'status' => CartRunStatus::Running,
            'cursor' => 0,
            'cart_id' => $cart->cartId,
            'delivery_fingerprint' => $cart->fingerprint(),
            'cart_context' => $cart->toRunContext(),
            'state' => [
                'event_context' => $event->state,
                'plan_snapshot' => $event->shopping_plan,
                'needs' => [$need],
                'current_need_index' => 0,
            ],
            'staged_items' => [],
            'warnings' => [],
        ]);
    }
}

final class FakeCartAgent implements CartProductAgent
{
    /** @param array<int, CartAgentDecisionData> $decisions */
    public function __construct(
        private readonly CartAgentPreparationData $preparation,
        private array $decisions,
        private readonly CartAgentAuditData $audit,
    ) {}

    public function prepare(
        array $eventContext,
        array $shoppingPlan,
        ?HarnessRun $harnessRun = null,
    ): CartAgentPreparationData {
        return $this->preparation;
    }

    public function decide(array $context, ?HarnessRun $harnessRun = null): CartAgentDecisionData
    {
        return array_shift($this->decisions);
    }

    public function audit(array $context, ?HarnessRun $harnessRun = null): CartAgentAuditData
    {
        return $this->audit;
    }
}

final class FakeCartGateway implements SilpoCartGateway
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $searchResults = [];

    /** @var array<int, string> */
    public array $searchQueries = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    public array $writes = [];

    public function __construct(public ?SilpoCartContextData $cart) {}

    public function getReadyCart(string $accessToken, ?HarnessRun $harnessRun = null): ?SilpoCartContextData
    {
        return $this->cart;
    }

    public function searchProducts(
        string $accessToken,
        SilpoCartContextData $cart,
        string $query,
        int $limit = 8,
        ?HarnessRun $harnessRun = null,
    ): array {
        $this->searchQueries[] = $query;

        return $this->searchResults[$query] ?? [];
    }

    public function getProductDetails(
        string $accessToken,
        SilpoCartContextData $cart,
        string $slug,
        ?HarnessRun $harnessRun = null,
    ): array {
        return [];
    }

    public function addOrUpdateProducts(
        string $accessToken,
        string $cartId,
        array $products,
        ?HarnessRun $harnessRun = null,
    ): array {
        $this->writes[] = $products;
        $items = collect($this->cart?->items ?? [])->keyBy('product_id');

        foreach ($products as $product) {
            $existing = $items->get($product['productId'], []);
            $price = (float) data_get($existing, 'price', $product['productId'] === 'water-1' ? 30 : 0);
            $items->put($product['productId'], [
                ...$existing,
                'product_id' => $product['productId'],
                'company_id' => $product['companyId'],
                'branch_id' => $product['branchId'],
                'name' => data_get($existing, 'name', 'Вода негазована 2 л'),
                'quantity' => (float) $product['quantity'],
                'price' => $price,
                'total' => $price * (float) $product['quantity'],
                'step' => 1,
                'stock' => 20,
            ]);
        }

        $this->cart = new SilpoCartContextData(
            cartId: $this->cart->cartId,
            deliveryType: $this->cart->deliveryType,
            branchId: $this->cart->branchId,
            companyId: $this->cart->companyId,
            slotStart: $this->cart->slotStart,
            slotEnd: $this->cart->slotEnd,
            items: $items->values()->all(),
            validations: [],
            slot: $this->cart->slot,
            totalAfterDiscounts: (float) $items->sum('total'),
        );

        return ['ok' => true];
    }
}
