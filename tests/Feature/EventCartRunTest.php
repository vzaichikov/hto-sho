<?php

namespace Tests\Feature;

use App\CartProductEvidence;
use App\CartRunMode;
use App\CartRunPhase;
use App\CartRunStatus;
use App\CartSyncStatus;
use App\Contracts\CartProductAgent;
use App\Contracts\SilpoCartGateway;
use App\Contracts\SilpoRouteIntentInterpreter;
use App\Data\CartAgentAuditData;
use App\Data\CartAgentDecisionData;
use App\Data\CartAgentPreparationData;
use App\Data\SilpoCartContextData;
use App\Data\SilpoCartRefreshCandidateData;
use App\Data\SilpoFulfilmentSnapshotData;
use App\Data\SilpoRouteIntentData;
use App\Jobs\AdvanceEventCartRunJob;
use App\Jobs\CommitEventCartRunJob;
use App\Models\Event;
use App\Models\EventCartRun;
use App\Models\HarnessRun;
use App\Models\SilpoConnection;
use App\Models\User;
use App\Services\CartCandidateSuitability;
use App\Services\CartQuantityCalculator;
use App\Services\GooseCartStatusService;
use App\Services\SilpoFulfilmentTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
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
                'У Сільпо ще немає кошика для Гуся. Відкрийте Сільпо, створіть кошик і повертайтеся.',
            );

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.store', $event), ['mode' => 'assisted'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_token');

        $this->assertDatabaseMissing('event_cart_runs', ['event_id' => $event->id]);
        Queue::assertNothingPushed();
    }

    public function test_expired_slot_requires_an_owner_confirmed_same_route_refresh_and_replay_does_not_write_twice(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        $stranger = User::factory()->create();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $candidate = new SilpoCartRefreshCandidateData(
            deliveryType: 'DeliveryHome',
            currentSlotStart: now()->subHour()->startOfHour()->toISOString(),
            currentSlotEnd: now()->subHour()->startOfHour()->addHour()->toISOString(),
            candidateSlotStart: now()->addDay()->startOfHour()->toISOString(),
            candidateSlotEnd: now()->addDay()->startOfHour()->addHour()->toISOString(),
            routeFingerprint: str_repeat('a', 64),
            currentSlotFingerprint: str_repeat('b', 64),
        );
        $refreshedCart = $this->readyCart();
        $gateway = new FakeCartGateway(null);
        $gateway->refreshCandidate = $candidate;
        $gateway->refreshResult = $refreshedCart;
        $this->app->instance(SilpoCartGateway::class, $gateway);
        $refreshUrl = route('events.silpo.cart-refresh', $event);
        $payload = [
            'route_fingerprint' => $candidate->routeFingerprint,
            'current_slot_fingerprint' => $candidate->currentSlotFingerprint,
            'slot_start' => $candidate->candidateSlotStart,
            'slot_end' => $candidate->candidateSlotEnd,
        ];

        $this->actingAs($stranger)
            ->postJson($refreshUrl, $payload)
            ->assertForbidden();
        $this->actingAs($owner)
            ->postJson($refreshUrl, [...$payload, 'route_fingerprint' => str_repeat('c', 64)])
            ->assertConflict();
        $this->assertSame(0, $gateway->refreshWrites);

        $this->actingAs($owner)
            ->postJson($refreshUrl, $payload)
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('cart.items_count', 1);
        $this->actingAs($owner)
            ->postJson($refreshUrl, $payload)
            ->assertOk()
            ->assertJsonPath('ready', true);

        $this->assertSame(1, $gateway->refreshWrites);
    }

    public function test_ready_cart_starts_a_persisted_background_run(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $gateway = new FakeCartGateway($cart);
        $gateway->catalogScopes = [
            'categories' => [['type' => 'category', 'slug' => 'voda-5087', 'depth' => 1]],
            'sets' => [['type' => 'set', 'slug' => 'dlia-lehkoi-vecheri']],
        ];
        $this->app->instance(SilpoCartGateway::class, $gateway);

        $preflight = $this->actingAs($owner)
            ->getJson(route('events.silpo.cart-preflight', $event))
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('current.delivery_label', 'Доставка Сільпо')
            ->assertJsonPath('current.items_count', 1)
            ->assertJsonPath('current.address_label', 'Київ, Хрещатик, 1')
            ->assertJsonStructure(['current' => ['review_token'], 'addresses']);

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.store', $event), [
                'mode' => 'assisted',
                'review_token' => $preflight->json('current.review_token'),
            ])
            ->assertAccepted()
            ->assertJsonStructure(['run_url']);

        $run = EventCartRun::query()->whereBelongsTo($event)->sole();
        $this->assertSame(CartRunStatus::Running, $run->status);
        $this->assertSame(CartRunPhase::Preparing, $run->phase);
        $this->assertSame($event->state_version, $run->plan_state_version);
        $this->assertSame('voda-5087', data_get($run->state, 'catalog_scopes.categories.0.slug'));
        $this->assertSame('dlia-lehkoi-vecheri', data_get($run->state, 'catalog_scopes.sets.0.slug'));
        $this->assertCount(2, $run->steps);
        $this->assertSame(CartSyncStatus::Syncing, $event->refresh()->cart_sync_status);
        Queue::assertPushed(
            AdvanceEventCartRunJob::class,
            fn (AdvanceEventCartRunJob $job): bool => $job->runId === $run->id && $job->expectedCursor === 0,
        );
    }

    public function test_owner_can_review_the_current_route_and_confirm_a_found_flat_home_address(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        $stranger = User::factory()->create();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $gateway = new FakeCartGateway($this->readyCart());
        $gateway->foundAddresses = [[
            'address' => 'Київ, вул. Саксаганського, 57-Б',
            'city' => 'Київ',
            'street' => 'вул. Саксаганського',
            'houseNumber' => '57-Б',
            'latitude' => 50.4406,
            'longitude' => 30.5072,
        ]];
        $gateway->fulfilmentReadbackAddress = [
            'addressType' => 'flat',
            'city' => 'Київ',
            'street' => 'вулиця Саксаганського',
            'house' => '57-Б',
            'latitude' => '50.4406',
            'longitude' => '30.5072',
            'postCode' => '01033',
            'polygonId' => 'polygon-1',
        ];
        $this->app->instance(SilpoCartGateway::class, $gateway);
        $preflight = $this->actingAs($owner)
            ->getJson(route('events.silpo.cart-preflight', $event))
            ->assertOk()
            ->assertJsonCount(1, 'addresses');
        $addressToken = $preflight->json('addresses.0.token');

        $this->actingAs($stranger)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'address_options',
                'token' => $addressToken,
            ])
            ->assertForbidden();

        $routes = $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'address_options',
                'token' => $addressToken,
            ])
            ->assertOk()
            ->assertJsonPath('options.0.writable', true);
        $routeToken = $routes->json('options.0.route_token');
        $slots = $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'slots',
                'token' => $routeToken,
            ])
            ->assertOk()
            ->assertJsonCount(1, 'slots');

        $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'review',
                'token' => $routeToken,
                'slot_start' => $slots->json('slots.0.start'),
                'slot_end' => $slots->json('slots.0.end'),
            ])
            ->assertOk()
            ->assertJsonPath('review.delivery_label', 'Доставка Сільпо')
            ->assertJsonPath('review.address_label', 'Київ, Хрещатик, 1')
            ->assertJsonStructure(['review_token']);

        $found = $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'address_search',
                'query' => 'Київ, вул. Саксаганського, 57-Б',
            ])
            ->assertOk()
            ->assertJsonPath('addresses.0.writable', true);

        $foundRoutes = $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'address_options',
                'token' => $found->json('addresses.0.token'),
            ])
            ->assertOk()
            ->assertJsonPath('options.0.address_label', 'Київ, вул. Саксаганського, 57-Б')
            ->assertJsonPath('options.0.branch_label', 'Київ, вул. Хрещатик, 1')
            ->assertJsonPath('options.0.writable', true)
            ->assertJsonPath('options.0.message', 'Гусь передасть Сільпо точну знайдену адресу як квартиру. Перед польотом ще раз звірте будинок і час.')
            ->assertJsonStructure(['options' => [['route_token']]]);
        $foundSlots = $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'slots',
                'token' => $foundRoutes->json('options.0.route_token'),
            ])
            ->assertOk();
        $foundReview = $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'review',
                'token' => $foundRoutes->json('options.0.route_token'),
                'slot_start' => $foundSlots->json('slots.0.start'),
                'slot_end' => $foundSlots->json('slots.0.end'),
            ])
            ->assertOk()
            ->assertJsonPath('review.address_label', 'Київ, вул. Саксаганського, 57-Б');

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.store', $event), [
                'mode' => 'assisted',
                'review_token' => $foundReview->json('review_token'),
            ])
            ->assertAccepted();

        $this->assertSame(1, $gateway->fulfilmentWrites);
        $this->assertSame([
            'addressType' => 'flat',
            'city' => 'Київ',
            'street' => 'вул. Саксаганського',
            'house' => '57-Б',
            'latitude' => '50.4406',
            'longitude' => '30.5072',
        ], $gateway->lastFulfilmentAddress);
        $this->assertSame(
            [['companyId' => 'company-1', 'branchId' => 'branch-1']],
            $gateway->lastFulfilmentShipments,
        );
        $this->assertSame(['existing-1'], collect($gateway->cart->items)->pluck('product_id')->all());
    }

    public function test_found_home_address_without_a_house_stays_read_only(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $gateway = new FakeCartGateway($this->readyCart());
        $gateway->foundAddresses = [[
            'address' => 'Київ, вул. Саксаганського',
            'city' => 'Київ',
            'street' => 'вул. Саксаганського',
            'latitude' => 50.4406,
            'longitude' => 30.5072,
        ]];
        $this->app->instance(SilpoCartGateway::class, $gateway);

        $found = $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'address_search',
                'query' => 'Київ, вул. Саксаганського',
            ])
            ->assertOk()
            ->assertJsonPath('addresses.0.writable', false);

        $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'address_options',
                'token' => $found->json('addresses.0.token'),
            ])
            ->assertOk()
            ->assertJsonPath('options.0.writable', false)
            ->assertJsonPath('options.0.route_token', null)
            ->assertJsonPath('options.0.message', 'Сільпо підтвердило точку, але не дало міста, вулиці або будинку. Гусь не буде домальовувати їх навмання.');

        $this->assertSame(0, $gateway->fulfilmentWrites);
    }

    public function test_searched_current_home_address_reuses_the_exact_cart_address(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $gateway = new FakeCartGateway($this->readyCart());
        $this->app->instance(SilpoCartGateway::class, $gateway);

        $found = $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'address_search',
                'query' => 'Київ, вул. Хрещатик, 1',
            ])
            ->assertOk()
            ->assertJsonPath('addresses.0.writable', true);

        $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'address_options',
                'token' => $found->json('addresses.0.token'),
            ])
            ->assertOk()
            ->assertJsonPath('options.0.address_label', 'Київ, вул. Хрещатик, 1')
            ->assertJsonPath('options.0.writable', true)
            ->assertJsonStructure(['options' => [['route_token']]]);

        $this->assertSame(0, $gateway->fulfilmentWrites);
    }

    public function test_saved_home_address_is_visible_but_not_offered_for_an_unsupported_cart_write(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $gateway = new FakeCartGateway($this->readyCart());
        $gateway->savedDeliveryAddresses = [[
            'addressType' => 'flat',
            'city' => 'Київ',
            'street' => 'Саксаганського',
            'house' => '57-Б',
            'latitude' => '50.4406',
            'longitude' => '30.5072',
        ]];
        $this->app->instance(SilpoCartGateway::class, $gateway);

        $preflight = $this->actingAs($owner)
            ->getJson(route('events.silpo.cart-preflight', $event))
            ->assertOk()
            ->assertJsonCount(2, 'addresses')
            ->assertJsonPath('addresses.1.label', 'Київ, Саксаганського, 57-Б')
            ->assertJsonPath('addresses.1.writable', false);

        $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'address_options',
                'token' => $preflight->json('addresses.1.token'),
            ])
            ->assertOk()
            ->assertJsonPath('options.0.writable', false)
            ->assertJsonPath('options.0.route_token', null);

        $this->assertSame(0, $gateway->fulfilmentWrites);
    }

    public function test_non_home_cart_address_is_not_reused_as_a_home_delivery_address(): void
    {
        $snapshot = new SilpoFulfilmentSnapshotData('cart-1', [
            'deliveryType' => 'SelfPickup',
            'address' => [
                'addressType' => 'self-pickup',
                'city' => 'Київ',
                'street' => 'вул. Басейна, 6',
                'latitude' => '50.4420',
                'longitude' => '30.5228',
            ],
        ]);

        $this->assertFalse($snapshot->hasReusableHomeAddress());
    }

    public function test_incomplete_or_rejected_route_intent_returns_manual_fallback_without_any_mcp_read(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $gateway = new FakeCartGateway($this->readyCart());
        $this->app->instance(SilpoCartGateway::class, $gateway);
        $this->app->instance(SilpoRouteIntentInterpreter::class, new FakeSilpoRouteIntentInterpreter(
            SilpoRouteIntentData::from($this->routeIntentPayload([
                'address_query' => 'Київ, вул. Саксаганського',
                'city' => 'Київ',
                'street' => 'вул. Саксаганського',
                'house' => null,
            ])),
        ));

        $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'intent',
                'query' => 'Додому на Саксаганського',
            ])
            ->assertOk()
            ->assertJsonPath('kind', 'clarification')
            ->assertJsonPath('manual_fallback', true)
            ->assertJsonPath('question', 'Який номер будинку має знайти Гусь?');

        $this->app->instance(
            SilpoRouteIntentInterpreter::class,
            new FakeSilpoRouteIntentInterpreter(exception: new RuntimeException('broken json')),
        );
        $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'intent',
                'query' => 'Щось дуже загадкове',
            ])
            ->assertOk()
            ->assertJsonPath('kind', 'clarification')
            ->assertJsonPath('manual_fallback', true);

        $this->assertSame(0, $gateway->snapshotReads);
        $this->assertSame([], $gateway->addressSearchQueries);
    }

    public function test_address_intent_requires_exact_mcp_candidate_confirmation_before_route_discovery(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $gateway = new FakeCartGateway($this->readyCart());
        $gateway->foundAddresses = [[
            'address' => 'Київ, вул. Саксаганського, 30/1',
            'city' => 'Київ',
            'street' => 'вул. Саксаганського',
            'houseNumber' => '30/1',
            'latitude' => 50.438,
            'longitude' => 30.509,
        ]];
        $this->app->instance(SilpoCartGateway::class, $gateway);
        $this->app->instance(SilpoRouteIntentInterpreter::class, new FakeSilpoRouteIntentInterpreter(
            SilpoRouteIntentData::from($this->routeIntentPayload([
                'address_query' => 'Київ, вул. Саксаганського, 57-Б',
                'city' => 'Київ',
                'street' => 'вул. Саксаганського',
                'house' => '57-Б',
                'delivery_preference' => 'home',
            ])),
        ));

        $intent = $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'intent',
                'query' => 'Доставка додому: Київ, вул. Саксаганського, 57-Б',
            ])
            ->assertOk()
            ->assertJsonPath('kind', 'address_candidates')
            ->assertJsonPath('heard', 'Доставка додому: Київ, вул. Саксаганського, 57-Б')
            ->assertJsonPath('addresses.0.label', 'Київ, вул. Саксаганського, 30/1');

        $this->assertSame(['Київ, вул. Саксаганського, 57-Б'], $gateway->addressSearchQueries);
        $this->assertSame(0, $gateway->deliveryTypeReads);
        $this->assertStringNotContainsString('Саксаганського', $intent->json('addresses.0.token'));

        $otherEvent = Event::factory()->ready()->for($owner)->create();
        $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $otherEvent), [
                'stage' => 'address_options',
                'token' => $intent->json('addresses.0.token'),
            ])
            ->assertConflict();

        $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'address_options',
                'token' => $intent->json('addresses.0.token'),
            ])
            ->assertOk()
            ->assertJsonPath('options.0.writable', true)
            ->assertJsonStructure(['options' => [['route_token']]]);

        $this->assertSame(1, $gateway->deliveryTypeReads);
        $this->assertSame(0, $gateway->fulfilmentWrites);
    }

    public function test_pickup_intent_carries_time_preference_and_highlights_one_live_slot_without_writing(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $localDate = CarbonImmutable::now('Europe/Kyiv')->addDay()->toDateString();
        $earlyStart = CarbonImmutable::parse($localDate.' 16:00', 'Europe/Kyiv')->toISOString();
        $recommendedStart = CarbonImmutable::parse($localDate.' 18:00', 'Europe/Kyiv')->toISOString();
        $gateway = new FakeCartGateway($this->readyCart());
        $gateway->availableDeliveryTypes = [[
            'deliveryType' => 'SelfPickup',
            'branchId' => 'branch-1',
        ]];
        $gateway->fulfilmentSlots = [[
            'start' => $earlyStart,
            'end' => CarbonImmutable::parse($earlyStart)->addHour()->toISOString(),
            'available' => true,
            'deliveryType' => 'SelfPickup',
            'deliveryCost' => 0,
            'minOrderCost' => 0,
        ], [
            'start' => $recommendedStart,
            'end' => CarbonImmutable::parse($recommendedStart)->addHour()->toISOString(),
            'available' => true,
            'deliveryType' => 'SelfPickup',
            'deliveryCost' => 0,
            'minOrderCost' => 0,
        ]];
        $this->app->instance(SilpoCartGateway::class, $gateway);
        $this->app->instance(SilpoRouteIntentInterpreter::class, new FakeSilpoRouteIntentInterpreter(
            SilpoRouteIntentData::from($this->routeIntentPayload([
                'delivery_preference' => 'self_pickup',
                'requested_local_date' => $localDate,
                'requested_time_from' => '18:00',
            ])),
        ));

        $intent = $this->actingAs($owner)->postJson(route('events.silpo.fulfilment.discover', $event), [
            'stage' => 'intent',
            'query' => 'Самовивіз у Києві на Хрещатику, 1 завтра після 18:00',
        ])->assertOk();
        $routes = $this->actingAs($owner)->postJson(route('events.silpo.fulfilment.discover', $event), [
            'stage' => 'address_options',
            'token' => $intent->json('addresses.0.token'),
        ])->assertOk()
            ->assertJsonPath('options.0.delivery_label', 'Самовивіз')
            ->assertJsonPath('options.0.preferred', true);
        $slots = $this->actingAs($owner)->postJson(route('events.silpo.fulfilment.discover', $event), [
            'stage' => 'slots',
            'token' => $routes->json('options.0.route_token'),
        ])->assertOk()
            ->assertJsonPath('slots.0.recommended', false)
            ->assertJsonPath('slots.1.recommended', true);

        $this->assertStringContainsString('Гусь підсвітив', $slots->json('preference_note'));

        $gateway->fulfilmentSlots = [$gateway->fulfilmentSlots[0]];
        $this->actingAs($owner)->postJson(route('events.silpo.fulfilment.discover', $event), [
            'stage' => 'slots',
            'token' => $routes->json('options.0.route_token'),
        ])->assertOk()
            ->assertJsonPath('slots.0.recommended', false)
            ->assertJsonPath(
                'preference_note',
                'Точного збігу з побажанням немає, тож Гусь показує всі свіжі вікна Сільпо.',
            );
        $this->assertSame(0, $gateway->fulfilmentWrites);
    }

    public function test_nova_poshta_intent_bypasses_address_search_and_applies_the_office_hint(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $gateway = new FakeCartGateway($this->readyCart());
        $this->app->instance(SilpoCartGateway::class, $gateway);
        $this->app->instance(SilpoRouteIntentInterpreter::class, new FakeSilpoRouteIntentInterpreter(
            SilpoRouteIntentData::from($this->routeIntentPayload([
                'address_query' => null,
                'city' => null,
                'street' => null,
                'house' => null,
                'delivery_preference' => 'nova_poshta',
                'nova_poshta_city' => 'Ірпінь',
                'nova_poshta_office_hint' => 'поштомат 28122',
            ])),
        ));

        $settlements = $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'intent',
                'query' => 'Новою поштою в Ірпінь, поштомат 28122',
            ])
            ->assertOk()
            ->assertJsonPath('kind', 'nova_settlements')
            ->assertJsonPath('office_hint', 'поштомат 28122');

        $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'nova_offices',
                'token' => $settlements->json('settlements.0.token'),
            ])
            ->assertOk()
            ->assertJsonPath('offices.0.label', 'Відділення №1');

        $this->assertSame([], $gateway->addressSearchQueries);
        $this->assertSame(['Ірпінь'], $gateway->novaPoshtaSettlementQueries);
        $this->assertSame(['поштомат 28122'], $gateway->novaPoshtaOfficeQueries);
        $this->assertSame(0, $gateway->fulfilmentWrites);
    }

    public function test_customer_route_payload_hides_informational_and_raw_silpo_validation_codes(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $gateway = new FakeCartGateway($this->readyCart([
            ['type' => 'info', 'message' => 'promotion.available'],
            ['type' => 'error', 'message' => 'order.cost.min'],
            ['type' => 'error', 'message' => 'timeslot.not_available'],
            ['type' => 'warning', 'message' => 'mystery.code'],
        ]));
        $this->app->instance(SilpoCartGateway::class, $gateway);

        $response = $this->actingAs($owner)
            ->getJson(route('events.silpo.cart-preflight', $event))
            ->assertOk()
            ->assertJsonCount(2, 'current.validations')
            ->assertJsonPath(
                'current.validations.0.message',
                'Цей час уже недоступний. Гусь допоможе обрати свіжий.',
            )
            ->assertJsonPath(
                'current.validations.1.message',
                'Сільпо просить додатково перевірити кошик.',
            );

        $encoded = $response->getContent();
        $this->assertStringNotContainsString('promotion.available', $encoded);
        $this->assertStringNotContainsString('order.cost.min', $encoded);
        $this->assertStringNotContainsString('Кошик ще не досяг мінімальної суми', $encoded);
        $this->assertStringNotContainsString('timeslot.not_available', $encoded);
        $this->assertStringNotContainsString('mystery.code', $encoded);
    }

    public function test_home_route_change_preserves_the_cart_address_and_shipments_while_sending_target_branch(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $gateway = new FakeCartGateway($this->readyCart());
        $originalAddress = $gateway->fulfilmentAddress;
        $gateway->availableDeliveryTypes = [[
            'deliveryType' => 'WideAssortDelivery',
            'branchId' => 'branch-2',
        ]];
        $gateway->fulfilmentBranches = [[
            'branchId' => 'branch-2',
            'companyId' => 'company-1',
            'city' => 'Київ',
            'address' => 'дор. Кільцева, 1',
            'latitude' => '50.4070',
            'longitude' => '30.3920',
            'open' => true,
        ]];
        $this->app->instance(SilpoCartGateway::class, $gateway);

        $preflight = $this->actingAs($owner)
            ->getJson(route('events.silpo.cart-preflight', $event))
            ->assertOk();
        $routes = $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'address_options',
                'token' => $preflight->json('addresses.0.token'),
            ])
            ->assertOk()
            ->assertJsonPath('options.0.delivery_type', 'WideAssortDelivery')
            ->assertJsonPath('options.0.address_label', 'Київ, Хрещатик, 1')
            ->assertJsonPath('options.0.branch_label', 'Київ, дор. Кільцева, 1');
        $slots = $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'slots',
                'token' => $routes->json('options.0.route_token'),
            ])
            ->assertOk();
        $review = $this->actingAs($owner)
            ->postJson(route('events.silpo.fulfilment.discover', $event), [
                'stage' => 'review',
                'token' => $routes->json('options.0.route_token'),
                'slot_start' => $slots->json('slots.0.start'),
                'slot_end' => $slots->json('slots.0.end'),
            ])
            ->assertOk();

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.store', $event), [
                'mode' => 'assisted',
                'review_token' => $review->json('review_token'),
            ])
            ->assertAccepted();

        $this->assertSame(1, $gateway->fulfilmentWrites);
        $this->assertSame('branch-2', $gateway->lastTargetBranchId);
        $this->assertSame($originalAddress, $gateway->lastFulfilmentAddress);
        $this->assertSame(
            [['companyId' => 'company-1', 'branchId' => 'branch-1']],
            $gateway->lastFulfilmentShipments,
        );
        $this->assertSame('branch-2', $gateway->cart->branchId);
        $this->assertSame(['existing-1'], collect($gateway->cart->items)->pluck('product_id')->all());
    }

    public function test_final_route_confirmation_updates_once_preserves_lines_and_replay_opens_the_same_run(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $gateway = new FakeCartGateway($this->readyCart());
        $this->app->instance(SilpoCartGateway::class, $gateway);
        $snapshot = $gateway->getFulfilmentSnapshot('test-token');
        $selection = [
            'cart_id' => $snapshot->cartId,
            'delivery_type' => 'SelfPickup',
            'address' => [
                'addressType' => 'self-pickup',
                'city' => 'Київ',
                'street' => 'вул. Велика Васильківська, 1',
                'latitude' => '50.4380',
                'longitude' => '30.5150',
            ],
            'shipments' => [['companyId' => 'company-2', 'branchId' => 'branch-2']],
            'slot_start' => $gateway->cart->slotStart,
            'slot_end' => $gateway->cart->slotEnd,
        ];
        $reviewToken = $this->app->make(SilpoFulfilmentTokenService::class)->issue(
            'fulfilment_review',
            $owner,
            $event,
            [
                'base_cart_fingerprint' => $snapshot->cartFingerprint(),
                'product_fingerprint' => $snapshot->productFingerprint(),
                'selection' => $selection,
                'summary' => [],
            ],
        );
        $payload = ['mode' => 'assisted', 'review_token' => $reviewToken];
        $first = $this->actingAs($owner)
            ->postJson(route('events.cart-runs.store', $event), $payload)
            ->assertAccepted();
        EventCartRun::query()->whereBelongsTo($event)->sole()->update([
            'status' => CartRunStatus::Synced,
            'phase' => CartRunPhase::Finished,
            'finished_at' => now(),
        ]);
        $second = $this->actingAs($owner)
            ->postJson(route('events.cart-runs.store', $event), $payload)
            ->assertAccepted();

        $this->assertSame($first->json('run_url'), $second->json('run_url'));
        $this->assertSame(1, $gateway->fulfilmentWrites);
        $this->assertSame('SelfPickup', $gateway->cart->deliveryType);
        $this->assertSame('branch-2', $gateway->cart->branchId);
        $this->assertSame(['existing-1'], collect($gateway->cart->items)->pluck('product_id')->all());
        $this->assertSame(1, EventCartRun::query()->whereBelongsTo($event)->count());
    }

    public function test_stale_review_cannot_overwrite_a_changed_cart(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $gateway = new FakeCartGateway($this->readyCart());
        $this->app->instance(SilpoCartGateway::class, $gateway);
        $snapshot = $gateway->getFulfilmentSnapshot('test-token');
        $selection = [
            ...$snapshot->currentSelection(),
            'delivery_type' => 'SelfPickup',
            'address' => [
                'addressType' => 'self-pickup',
                'city' => 'Київ',
                'street' => 'вул. Велика Васильківська, 1',
                'latitude' => '50.4380',
                'longitude' => '30.5150',
            ],
        ];
        $reviewToken = $this->app->make(SilpoFulfilmentTokenService::class)->issue(
            'fulfilment_review',
            $owner,
            $event,
            [
                'base_cart_fingerprint' => $snapshot->cartFingerprint(),
                'product_fingerprint' => $snapshot->productFingerprint(),
                'selection' => $selection,
                'summary' => [],
            ],
        );
        $gateway->fulfilmentAddress['address'] = 'Київ, інша адреса, 99';

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.store', $event), [
                'mode' => 'auto',
                'review_token' => $reviewToken,
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Маршрут кошика вже змінився. Гусь не буде перетирати його навмання.');

        $this->assertSame(0, $gateway->fulfilmentWrites);
        $this->assertDatabaseMissing('event_cart_runs', ['event_id' => $event->id]);
        Queue::assertNothingPushed();
    }

    public function test_final_confirmation_rechecks_the_slot_before_any_route_write(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $gateway = new FakeCartGateway($this->readyCart());
        $this->app->instance(SilpoCartGateway::class, $gateway);
        $snapshot = $gateway->getFulfilmentSnapshot('test-token');
        $reviewToken = $this->app->make(SilpoFulfilmentTokenService::class)->issue(
            'fulfilment_review',
            $owner,
            $event,
            [
                'base_cart_fingerprint' => $snapshot->cartFingerprint(),
                'product_fingerprint' => $snapshot->productFingerprint(),
                'selection' => $snapshot->currentSelection(),
                'summary' => [],
            ],
        );
        $gateway->slotsAvailable = false;

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.store', $event), [
                'mode' => 'assisted',
                'review_token' => $reviewToken,
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Обраний час уже вислизнув. Гусь нічого не змінював — оберіть інший.');

        $this->assertSame(0, $gateway->fulfilmentWrites);
        Queue::assertNothingPushed();
    }

    public function test_single_product_flow_requires_confirmation_then_writes_one_absolute_batch_and_preserves_other_items(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $gateway = new FakeCartGateway($this->readyCart());
        $gateway->searchResults['вода питна'] = [[
            ...$this->waterProduct(),
            'id' => 'water-overage',
            'name' => 'Вода негазована 3 л',
            'slug' => 'water-3l',
            'displayRatio' => '3 л',
        ], $this->waterProduct()];
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

        $reviewToken = $this->actingAs($owner)
            ->getJson(route('events.silpo.cart-preflight', $event))
            ->assertOk()
            ->json('current.review_token');

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.store', $event), [
                'mode' => 'auto',
                'review_token' => $reviewToken,
            ])
            ->assertAccepted();

        $run = EventCartRun::query()->whereBelongsTo($event)->sole();
        $quantities = new CartQuantityCalculator;
        $statuses = new GooseCartStatusService;

        (new AdvanceEventCartRunJob($run->id, 0))->handle(
            $agent,
            $gateway,
            $quantities,
            $statuses,
            new CartCandidateSuitability,
        );
        $run->refresh();
        $this->assertSame(CartRunPhase::Searching, $run->phase);

        (new AdvanceEventCartRunJob($run->id, $run->cursor))->handle(
            $agent,
            $gateway,
            $quantities,
            $statuses,
            new CartCandidateSuitability,
        );
        $run->refresh();
        $this->assertSame(['вода питна'], $gateway->searchQueries);
        $this->assertSame(CartRunPhase::Deciding, $run->phase);
        $this->assertSame('water-1', data_get($run->state, 'last_candidates.0.product_id'));

        (new AdvanceEventCartRunJob($run->id, $run->cursor))->handle(
            $agent,
            $gateway,
            $quantities,
            $statuses,
            new CartCandidateSuitability,
        );
        $run->refresh();
        $this->assertSame(CartRunPhase::Auditing, $run->phase);
        $this->assertSame(2.0, (float) $run->staged_items[0]['quantity']);
        $this->assertSame('exact', $run->staged_items[0]['match_evidence']);
        $this->assertSame('not_required', $run->staged_items[0]['safety_evidence']);
        $this->assertSame(
            '«Вода негазована 2 л» вибрано для «Вода питна»: товар відповідає потрібній ролі та пройшов перевірки доступності й відомих заборон.',
            $run->staged_items[0]['selection_explanation'],
        );
        $this->assertNull($run->staged_items[0]['review_note']);

        (new AdvanceEventCartRunJob($run->id, $run->cursor))->handle(
            $agent,
            $gateway,
            $quantities,
            $statuses,
            new CartCandidateSuitability,
        );
        $run->refresh();
        $this->assertSame(CartRunPhase::ReadyToCommit, $run->phase);
        $this->assertSame(CartRunStatus::WaitingForConfirmation, $run->status);
        Queue::assertNotPushed(CommitEventCartRunJob::class);

        $this->actingAs($owner)
            ->getJson(route('events.cart-runs.show', [$event, $run]))
            ->assertOk()
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('confirm_url', route('events.cart-runs.confirm', [$event, $run]))
            ->assertJsonPath('staged_items.0.product_id', 'water-1')
            ->assertJsonPath('staged_items.0.match_evidence', 'exact')
            ->assertJsonPath('staged_items.0.safety_evidence', 'not_required')
            ->assertJsonPath('staged_items.0.quantity', 2);

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.confirm', [$event, $run]))
            ->assertAccepted();

        $run->refresh();
        $this->assertSame(CartRunStatus::Committing, $run->status);
        Queue::assertPushed(
            CommitEventCartRunJob::class,
            fn (CommitEventCartRunJob $job): bool => $job->runId === $run->id
                && $job->expectedCursor === $run->cursor,
        );

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

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.confirm', [$event, $run]))
            ->assertConflict();
        $this->assertCount(1, $gateway->writes);
    }

    public function test_confirmation_rejects_a_stale_plan_without_dispatching_or_writing(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $gateway = new FakeCartGateway($cart);
        $this->app->instance(SilpoCartGateway::class, $gateway);
        $run = EventCartRun::factory()->for($event)->create([
            'status' => CartRunStatus::WaitingForConfirmation,
            'phase' => CartRunPhase::ReadyToCommit,
            'plan_state_version' => $event->state_version,
            'cart_id' => $cart->cartId,
            'delivery_fingerprint' => $cart->fingerprint(),
            'cart_context' => $cart->toRunContext(),
            'staged_items' => [['product_id' => 'water-1', 'quantity' => 2]],
        ]);
        $event->increment('state_version');

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.confirm', [$event, $run]))
            ->assertConflict();

        $this->assertSame(CartRunStatus::Stale, $run->refresh()->status);
        Queue::assertNotPushed(CommitEventCartRunJob::class);
        $this->assertSame([], $gateway->writes);
    }

    public function test_confirmation_rejects_a_changed_delivery_slot_and_is_owner_scoped(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        $stranger = User::factory()->create();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $originalCart = $this->readyCart();
        $changedCart = new SilpoCartContextData(
            cartId: $originalCart->cartId,
            deliveryType: $originalCart->deliveryType,
            branchId: $originalCart->branchId,
            companyId: $originalCart->companyId,
            slotStart: now()->addDays(2)->startOfHour()->toISOString(),
            slotEnd: now()->addDays(2)->startOfHour()->addHour()->toISOString(),
            items: $originalCart->items,
            validations: [],
            slot: $originalCart->slot,
            totalAfterDiscounts: $originalCart->totalAfterDiscounts,
        );
        $gateway = new FakeCartGateway($changedCart);
        $this->app->instance(SilpoCartGateway::class, $gateway);
        $run = EventCartRun::factory()->for($event)->create([
            'status' => CartRunStatus::WaitingForConfirmation,
            'phase' => CartRunPhase::ReadyToCommit,
            'plan_state_version' => $event->state_version,
            'cart_id' => $originalCart->cartId,
            'delivery_fingerprint' => $originalCart->fingerprint(),
            'cart_context' => $originalCart->toRunContext(),
            'staged_items' => [['product_id' => 'water-1', 'quantity' => 2]],
        ]);

        $this->actingAs($stranger)
            ->postJson(route('events.cart-runs.confirm', [$event, $run]))
            ->assertForbidden();
        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.confirm', [$event, $run]))
            ->assertConflict();

        $this->assertSame(CartRunStatus::Stale, $run->refresh()->status);
        Queue::assertNotPushed(CommitEventCartRunJob::class);
        $this->assertSame([], $gateway->writes);
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
            new CartCandidateSuitability,
        );

        $run->refresh();
        $this->assertSame(CartRunStatus::WaitingForAnswer, $run->status);
        $this->assertNotNull($run->blocker);
        $this->assertSame([], $gateway->searchQueries);
        Queue::assertNotPushed(CommitEventCartRunJob::class);
    }

    public function test_exhausted_text_search_browses_the_best_matching_catalog_category_before_asking(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $need = [
            ...$this->waterNeed(),
            'key' => 'zucchini',
            'name' => 'кабачки для гриля',
            'category' => 'food',
            'quantity' => 1.0,
            'unit' => 'кг',
            'note' => 'Сирий продукт для мангалу',
            'search_query' => 'цукіні',
            'search_queries' => ['кабачки', 'цукіні'],
            'attempts' => [
                ['query' => 'кабачки', 'total_found' => 0],
                ['query' => 'кабачки для гриля', 'total_found' => 0],
            ],
        ];
        $run = EventCartRun::factory()->for($event)->create([
            'mode' => CartRunMode::Assisted,
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
                'catalog_scopes' => [
                    'categories' => [
                        ['type' => 'category', 'slug' => 'frukty-ovochi-4788', 'depth' => 0, 'parent_slug' => null],
                        ['type' => 'category', 'slug' => 'ovochi-4808', 'depth' => 1, 'parent_slug' => 'frukty-ovochi-4788'],
                        ['type' => 'category', 'slug' => 'kabachky-tsukini-4811', 'depth' => 2, 'parent_slug' => 'ovochi-4808'],
                    ],
                    'sets' => [],
                ],
                'current_need_index' => 0,
            ],
            'staged_items' => [],
        ]);
        $gateway = new FakeCartGateway($cart);
        $gateway->browseResults['category:kabachky-tsukini-4811'] = [[
            ...$this->waterProduct(),
            'id' => 'zucchini-1',
            'name' => 'Кабачок зелений',
            'slug' => 'kabachok-zelenyi',
            'weighted' => true,
            'step' => 0.1,
        ]];
        $agent = new FakeCartAgent(
            new CartAgentPreparationData([]),
            [new CartAgentDecisionData(
                action: 'retry',
                selectedProductId: null,
                query: 'цукіні',
                quantity: null,
                reason: 'Модель просить зайвий повтор після безпечного category fallback.',
                question: null,
                audit: $this->audit([], ['zucchini'], false),
            )],
            $this->audit([], ['zucchini'], false),
        );

        (new AdvanceEventCartRunJob($run->id, 0))->handle(
            $agent,
            $gateway,
            new CartQuantityCalculator,
            new GooseCartStatusService,
            new CartCandidateSuitability,
        );

        $run->refresh();
        $this->assertSame(CartRunPhase::Searching, $run->phase);
        $this->assertSame(['цукіні'], $gateway->searchQueries);
        $this->assertSame([], $gateway->browseScopes);

        (new AdvanceEventCartRunJob($run->id, $run->cursor))->handle(
            $agent,
            $gateway,
            new CartQuantityCalculator,
            new GooseCartStatusService,
            new CartCandidateSuitability,
        );

        $run->refresh();
        $this->assertSame(CartRunStatus::Running, $run->status);
        $this->assertSame([
            ['type' => 'category', 'slug' => 'kabachky-tsukini-4811'],
        ], $gateway->browseScopes);
        $this->assertSame([20], $gateway->browseLimits);
        $this->assertSame(CartRunPhase::Deciding, $run->phase);
        $this->assertSame('zucchini-1', data_get($run->state, 'last_candidates.0.product_id'));
        $this->assertSame('category', data_get($run->state, 'needs.0.browse_attempts.0.type'));
        Queue::assertPushed(AdvanceEventCartRunJob::class);

        (new AdvanceEventCartRunJob($run->id, $run->cursor))->handle(
            $agent,
            $gateway,
            new CartQuantityCalculator,
            new GooseCartStatusService,
            new CartCandidateSuitability,
        );

        $run->refresh();
        $this->assertSame(CartRunPhase::Auditing, $run->phase);
        $this->assertSame('zucchini-1', data_get($run->staged_items, '0.product_id'));
    }

    public function test_assisted_mode_tries_a_positive_lemma_when_long_prepared_queries_end(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $run = $this->exhaustedRun($event, $cart, CartRunMode::Assisted);
        $state = $run->state;
        $state['needs'][0]['search_queries'] = ['вода 1', 'вода 2', 'вода 3', 'вода 4'];
        $state['needs'][0]['attempts'] = collect(range(1, 4))
            ->map(fn (int $attempt): array => ['query' => "вода {$attempt}", 'total_found' => 0])
            ->all();
        $state['last_candidates'] = [];
        $run->update([
            'phase' => CartRunPhase::Deciding,
            'state' => $state,
        ]);
        $decision = new CartAgentDecisionData(
            action: 'skip',
            selectedProductId: null,
            query: null,
            quantity: null,
            reason: 'Підготовлені пошуки вичерпано.',
            question: null,
            audit: $this->audit([], ['water'], false),
        );

        $gateway = new FakeCartGateway($cart);

        (new AdvanceEventCartRunJob($run->id, 0))->handle(
            new FakeCartAgent(new CartAgentPreparationData([]), [$decision], $this->audit([], ['water'], false)),
            $gateway,
            new CartQuantityCalculator,
            new GooseCartStatusService,
            new CartCandidateSuitability,
        );

        $run->refresh();
        $this->assertSame(CartRunStatus::Running, $run->status);
        $this->assertSame(CartRunPhase::Searching, $run->phase);
        $this->assertSame('вода', data_get($run->state, 'needs.0.search_query'));
        $this->assertSame(1, $run->cursor);
        Queue::assertPushed(AdvanceEventCartRunJob::class);
    }

    public function test_assisted_answer_can_request_one_new_exact_catalog_query(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $run = $this->exhaustedRun($event, $cart, CartRunMode::Assisted);
        $state = $run->state;
        $state['blocked_phase'] = CartRunPhase::Deciding->value;
        $run->update([
            'status' => CartRunStatus::WaitingForAnswer,
            'phase' => CartRunPhase::Deciding,
            'state' => $state,
            'blocker' => 'Підкажіть точну безпечну заміну.',
        ]);

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.continue', [$event, $run]), [
                'answer' => 'Шукати: телячий биток',
            ])
            ->assertAccepted();

        $run->refresh();
        $this->assertSame(CartRunStatus::Running, $run->status);
        $this->assertSame(CartRunPhase::Searching, $run->phase);
        $this->assertSame('телячий биток', data_get($run->state, 'needs.0.search_query'));
        $this->assertSame('Шукати: телячий биток', data_get($run->state, 'needs.0.human_answer'));
        $this->assertTrue(data_get($run->state, 'needs.0.assisted_search_pending'));
        Queue::assertPushed(AdvanceEventCartRunJob::class);

        $gateway = new FakeCartGateway($cart);
        $gateway->searchResults['телячий биток'] = [$this->waterProduct()];
        (new AdvanceEventCartRunJob($run->id, $run->cursor))->handle(
            new FakeCartAgent(new CartAgentPreparationData([]), [], $this->audit([], ['water'], false)),
            $gateway,
            new CartQuantityCalculator,
            new GooseCartStatusService,
            new CartCandidateSuitability,
        );

        $run->refresh();
        $this->assertSame(['телячий биток'], $gateway->searchQueries);
        $this->assertCount(7, data_get($run->state, 'needs.0.attempts'));
        $this->assertFalse((bool) data_get($run->state, 'needs.0.assisted_search_pending', false));
    }

    public function test_assisted_answer_journals_a_long_safety_blocker_with_a_bounded_title(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $run = $this->exhaustedRun($event, $this->readyCart(), CartRunMode::Assisted);
        $harnessRun = HarnessRun::factory()->for($event)->create();
        $state = $run->state;
        $state['blocked_phase'] = CartRunPhase::Deciding->value;
        $run->update([
            'harness_run_id' => $harnessRun->id,
            'status' => CartRunStatus::WaitingForAnswer,
            'phase' => CartRunPhase::Deciding,
            'state' => $state,
            'blocker' => str_repeat('Детальний доказ відсутності безпечного товару. ', 12),
        ]);

        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.continue', [$event, $run]), [
                'answer' => 'Пропустити небезпечну позицію.',
            ])
            ->assertAccepted();

        $entry = $harnessRun->entries()->sole();
        $this->assertLessThanOrEqual(255, mb_strlen($entry->title));
        $this->assertSame('Пропустити небезпечну позицію.', $entry->message);
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
            new CartCandidateSuitability,
        );

        $run->refresh();
        $this->assertSame(CartRunStatus::Running, $run->status);
        $this->assertSame(CartRunPhase::Auditing, $run->phase);
        $this->assertSame('skipped', data_get($run->state, 'needs.0.status'));
        $this->assertNull($run->blocker);
        $this->assertSame(['Не знайдено: Вода питна.'], $run->warnings);
    }

    public function test_high_risk_need_stages_the_best_candidate_after_three_inconclusive_inspections(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $need = [
            ...$this->waterNeed(),
            'key' => 'sauce',
            'name' => 'соус без арахісу',
            'category' => 'food',
            'search_queries' => ['соус', 'кетчуп'],
            'inspected_products' => ['sauce-1', 'sauce-2', 'sauce-3'],
        ];
        $candidate = [
            ...$this->waterProduct(),
            'product_id' => 'sauce-4',
            'name' => 'Кетчуп томатний',
            'slug' => 'ketchup-tomatnyi',
        ];
        $run = EventCartRun::factory()->for($event)->create([
            'mode' => CartRunMode::Assisted,
            'phase' => CartRunPhase::Deciding,
            'status' => CartRunStatus::Running,
            'cursor' => 0,
            'plan_state_version' => $event->state_version,
            'cart_id' => $cart->cartId,
            'delivery_fingerprint' => $cart->fingerprint(),
            'cart_context' => $cart->toRunContext(),
            'state' => [
                'event_context' => ['summary' => 'Сильна алергія на арахіс.'],
                'plan_snapshot' => $event->shopping_plan,
                'needs' => [$need],
                'current_need_index' => 0,
                'last_candidates' => [$candidate],
                'last_details' => null,
            ],
            'staged_items' => [],
        ]);
        $decision = new CartAgentDecisionData(
            action: 'select',
            selectedProductId: 'sauce-4',
            query: null,
            quantity: 2,
            reason: 'Обрати четвертий кандидат.',
            question: null,
            audit: $this->audit([], ['sauce'], false),
        );

        (new AdvanceEventCartRunJob($run->id, 0))->handle(
            new FakeCartAgent(new CartAgentPreparationData([]), [$decision], $this->audit([], ['sauce'], false)),
            new FakeCartGateway($cart),
            new CartQuantityCalculator,
            new GooseCartStatusService,
            new CartCandidateSuitability,
        );

        $run->refresh();
        $this->assertSame(CartRunStatus::Running, $run->status);
        $this->assertSame(CartRunPhase::Auditing, $run->phase);
        $this->assertSame('sauce-4', data_get($run->staged_items, '0.product_id'));
        $this->assertSame('unverified', data_get($run->staged_items, '0.safety_evidence'));
        $this->assertStringContainsString('❓', (string) data_get($run->staged_items, '0.review_note'));
    }

    public function test_selection_outside_filtered_candidates_is_retried_without_throwing(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $run = $this->exhaustedRun($event, $cart, CartRunMode::Assisted);
        $state = $run->state;
        $state['last_candidates'] = [[
            ...$this->waterProduct(),
            'product_id' => 'water-1',
        ]];
        $state['catalog_scopes'] = [
            'categories' => [[
                'type' => 'category',
                'slug' => 'voda-5087',
                'depth' => 1,
            ]],
            'sets' => [],
        ];
        $run->update(['phase' => CartRunPhase::Deciding, 'state' => $state]);
        $decision = new CartAgentDecisionData(
            action: 'select',
            selectedProductId: 'outside-filtered-set',
            query: null,
            quantity: 2,
            reason: 'Застарілий ідентифікатор.',
            question: null,
            audit: $this->audit([], ['water'], false),
        );

        $agent = new FakeCartAgent(
            new CartAgentPreparationData([]),
            [$decision, $decision],
            $this->audit([], ['water'], false),
        );

        (new AdvanceEventCartRunJob($run->id, 0))->handle(
            $agent,
            new FakeCartGateway($cart),
            new CartQuantityCalculator,
            new GooseCartStatusService,
            new CartCandidateSuitability,
        );

        $run->refresh();
        $this->assertSame(CartRunStatus::Running, $run->status);
        $this->assertSame(CartRunPhase::Deciding, $run->phase);
        $this->assertSame(1, data_get($run->state, 'needs.0.invalid_decision_count'));

        (new AdvanceEventCartRunJob($run->id, $run->cursor))->handle(
            $agent,
            new FakeCartGateway($cart),
            new CartQuantityCalculator,
            new GooseCartStatusService,
            new CartCandidateSuitability,
        );

        $run->refresh();
        $this->assertSame(CartRunStatus::Running, $run->status);
        $this->assertSame(CartRunPhase::Searching, $run->phase);
        $this->assertNull($run->blocker);
    }

    public function test_repeated_invalid_selection_uses_the_next_lexical_query_before_asking(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $need = $this->waterNeed();
        $need['search_queries'] = ['вода питна', 'вода негазована', 'мінеральна вода'];
        $need['attempts'] = [['query' => 'вода питна', 'total_found' => 1]];
        $run = EventCartRun::factory()->for($event)->create([
            'phase' => CartRunPhase::Deciding,
            'status' => CartRunStatus::Running,
            'cursor' => 0,
            'plan_state_version' => $event->state_version,
            'cart_id' => $cart->cartId,
            'delivery_fingerprint' => $cart->fingerprint(),
            'cart_context' => $cart->toRunContext(),
            'state' => [
                'event_context' => $event->state,
                'plan_snapshot' => $event->shopping_plan,
                'needs' => [$need],
                'current_need_index' => 0,
                'last_candidates' => [$this->waterProduct()],
            ],
            'staged_items' => [],
        ]);
        $decision = new CartAgentDecisionData(
            action: 'select',
            selectedProductId: 'outside-filtered-set',
            query: null,
            quantity: 2,
            reason: 'Застарілий ідентифікатор.',
            question: null,
            audit: $this->audit([], ['water'], false),
        );
        $agent = new FakeCartAgent(
            new CartAgentPreparationData([]),
            [$decision, $decision],
            $this->audit([], ['water'], false),
        );

        foreach ([0, 1] as $attempt) {
            (new AdvanceEventCartRunJob($run->id, $run->cursor))->handle(
                $agent,
                new FakeCartGateway($cart),
                new CartQuantityCalculator,
                new GooseCartStatusService,
                new CartCandidateSuitability,
            );
            $run->refresh();
        }

        $this->assertSame(CartRunStatus::Running, $run->status);
        $this->assertSame(CartRunPhase::Searching, $run->phase);
        $this->assertSame('вода', data_get($run->state, 'needs.0.search_query'));
        $this->assertNull($run->blocker);
    }

    public function test_repeated_invalid_selection_uses_the_first_vetted_low_risk_catalog_candidate(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $need = [
            ...$this->waterNeed(),
            'attempts' => collect(range(1, 6))
                ->map(fn (int $attempt): array => ['query' => "вода {$attempt}", 'total_found' => 0])
                ->all(),
            'browse_attempts' => [[
                'type' => 'category',
                'slug' => 'voda-5087',
                'total_found' => 1,
            ]],
        ];
        $candidate = [
            ...$this->waterProduct(),
            'product_id' => 'water-1',
            'catalog_scope' => [
                'type' => 'category',
                'slug' => 'voda-5087',
                'matched' => true,
            ],
        ];
        $run = EventCartRun::factory()->for($event)->create([
            'phase' => CartRunPhase::Deciding,
            'status' => CartRunStatus::Running,
            'cursor' => 0,
            'plan_state_version' => $event->state_version,
            'cart_id' => $cart->cartId,
            'delivery_fingerprint' => $cart->fingerprint(),
            'cart_context' => $cart->toRunContext(),
            'state' => [
                'event_context' => $event->state,
                'plan_snapshot' => $event->shopping_plan,
                'needs' => [$need],
                'current_need_index' => 0,
                'last_candidates' => [$candidate],
            ],
            'staged_items' => [],
        ]);
        $decision = new CartAgentDecisionData(
            action: 'select',
            selectedProductId: 'outside-filtered-set',
            query: null,
            quantity: 2,
            reason: 'Застарілий ідентифікатор.',
            question: null,
            audit: $this->audit([], ['water'], false),
        );
        $agent = new FakeCartAgent(
            new CartAgentPreparationData([]),
            [$decision, $decision],
            $this->audit([], ['water'], false),
        );

        foreach ([0, 1] as $attempt) {
            (new AdvanceEventCartRunJob($run->id, $run->cursor))->handle(
                $agent,
                new FakeCartGateway($cart),
                new CartQuantityCalculator,
                new GooseCartStatusService,
                new CartCandidateSuitability,
            );
            $run->refresh();
        }

        $this->assertSame(CartRunPhase::Auditing, $run->phase);
        $this->assertSame('water-1', data_get($run->staged_items, '0.product_id'));
        $this->assertNull($run->blocker);
    }

    public function test_category_selection_prefers_a_better_package_fit_over_a_worse_valid_model_choice(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $need = [
            'key' => 'vegetable',
            'source_index' => 0,
            'name' => 'огірки',
            'category' => 'food',
            'quantity' => 1,
            'unit' => 'кг',
            'note' => 'Свіжа овочева позиція до мангалу.',
            'search_query' => 'огірки',
            'search_queries' => ['огірки', 'огірок'],
            'status' => 'pending',
            'attempts' => [['query' => 'огірки'], ['query' => 'огірок']],
            'browse_attempts' => [[
                'type' => 'category',
                'slug' => 'ogirky-4823',
                'total_found' => 0,
            ]],
            'inspected_products' => [],
            'selected_item' => null,
            'human_answer' => null,
        ];
        $catalogScope = [
            'type' => 'category',
            'slug' => 'ovochi-4808',
            'matched' => true,
        ];
        $tomato = [
            'product_id' => 'tomato-1',
            'name' => 'Томат сливка жовтий',
            'slug' => 'tomat-slyvka-zhovtyi',
            'price' => 90,
            'stock' => 20,
            'available' => true,
            'weighted' => true,
            'step' => 0.25,
            'display_ratio' => '100г',
            'catalog_scope' => $catalogScope,
        ];
        $pumpkin = [
            ...$tomato,
            'product_id' => 'pumpkin-1',
            'name' => 'Гарбуз Баттернат',
            'slug' => 'garbuz-batternat',
            'price' => 80,
            'step' => 2.5,
        ];
        $run = EventCartRun::factory()->for($event)->create([
            'phase' => CartRunPhase::Deciding,
            'status' => CartRunStatus::Running,
            'cursor' => 0,
            'plan_state_version' => $event->state_version,
            'cart_id' => $cart->cartId,
            'delivery_fingerprint' => $cart->fingerprint(),
            'cart_context' => $cart->toRunContext(),
            'state' => [
                'event_context' => $event->state,
                'plan_snapshot' => $event->shopping_plan,
                'needs' => [$need],
                'current_need_index' => 0,
                'last_candidates' => [$tomato, $pumpkin],
            ],
            'staged_items' => [],
        ]);
        $agent = new FakeCartAgent(
            new CartAgentPreparationData([]),
            [new CartAgentDecisionData(
                action: 'select',
                selectedProductId: 'pumpkin-1',
                query: null,
                quantity: 1,
                reason: 'Модель обрала дешевший, але надто великий крок.',
                question: null,
                audit: $this->audit([], ['vegetable'], false),
            )],
            $this->audit(['vegetable'], [], true),
        );

        (new AdvanceEventCartRunJob($run->id, 0))->handle(
            $agent,
            new FakeCartGateway($cart),
            new CartQuantityCalculator,
            new GooseCartStatusService,
            new CartCandidateSuitability,
        );
        $run->refresh();

        $this->assertSame('tomato-1', data_get($run->staged_items, '0.product_id'));
        $this->assertSame(1.0, (float) data_get($run->staged_items, '0.quantity'));
    }

    public function test_search_filters_a_ready_marinade_for_an_event_that_forbids_it(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $need = [
            ...$this->waterNeed(),
            'key' => 'pork',
            'name' => 'свинина на шашлик',
            'quantity' => 2.0,
            'unit' => 'кг',
            'note' => 'Окремо під шашлик.',
            'search_query' => 'свинина для шашлику',
        ];
        $plan = [
            ...$event->shopping_plan,
            'summary' => 'Шашлик готуємо самі, без готових маринадів.',
        ];
        $run = EventCartRun::factory()->for($event)->create([
            'phase' => CartRunPhase::Searching,
            'status' => CartRunStatus::Running,
            'cursor' => 0,
            'plan_state_version' => $event->state_version,
            'cart_id' => $cart->cartId,
            'delivery_fingerprint' => $cart->fingerprint(),
            'cart_context' => $cart->toRunContext(),
            'state' => [
                'event_context' => ['summary' => 'Фінально: без готових маринадів.'],
                'plan_snapshot' => $plan,
                'needs' => [$need],
                'current_need_index' => 0,
            ],
            'staged_items' => [],
        ]);
        $gateway = new FakeCartGateway($cart);
        $gateway->searchResults['свинина для шашлику'] = [
            [
                ...$this->waterProduct(),
                'id' => 'marinated-pork',
                'name' => 'Свинячий шашлик маринований',
                'slug' => 'svyniachyi-shashlyk-marynovanyi',
                'weighted' => true,
                'step' => 0.5,
            ],
            [
                ...$this->waterProduct(),
                'id' => 'raw-pork-neck',
                'name' => 'Шия свиняча охолоджена',
                'slug' => 'shyia-svyniacha-okholodzhena',
                'weighted' => true,
                'step' => 0.5,
            ],
        ];

        (new AdvanceEventCartRunJob($run->id, 0))->handle(
            new FakeCartAgent(new CartAgentPreparationData([]), [], $this->audit([], ['pork'], false)),
            $gateway,
            new CartQuantityCalculator,
            new GooseCartStatusService,
            new CartCandidateSuitability,
        );

        $run->refresh();
        $this->assertSame(CartRunPhase::Deciding, $run->phase);
        $this->assertSame(['raw-pork-neck'], collect(data_get($run->state, 'last_candidates'))->pluck('product_id')->all());
        $this->assertSame(1, data_get($run->state, 'needs.0.attempts.0.total_found'));
    }

    public function test_empty_safe_pork_results_use_a_positive_catalog_lemma_before_asking_the_model(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $need = [
            ...$this->waterNeed(),
            'key' => 'pork',
            'name' => 'свинина на шашлик',
            'quantity' => 2.0,
            'unit' => 'кг',
            'note' => 'Без готових маринадів.',
            'search_query' => 'свинина для шашлику',
            'search_queries' => ['свинина для шашлику', 'сирий свинячий відруб'],
        ];
        $run = EventCartRun::factory()->for($event)->create([
            'phase' => CartRunPhase::Searching,
            'status' => CartRunStatus::Running,
            'cursor' => 0,
            'plan_state_version' => $event->state_version,
            'cart_id' => $cart->cartId,
            'delivery_fingerprint' => $cart->fingerprint(),
            'cart_context' => $cart->toRunContext(),
            'state' => [
                'event_context' => ['summary' => 'Без готових маринадів.'],
                'plan_snapshot' => $event->shopping_plan,
                'needs' => [$need],
                'current_need_index' => 0,
            ],
            'staged_items' => [],
        ]);
        $gateway = new FakeCartGateway($cart);
        $gateway->searchResults['свинина для шашлику'] = [[
            ...$this->waterProduct(),
            'id' => 'marinated-pork',
            'name' => 'Свинячий шашлик маринований',
            'slug' => 'svyniachyi-shashlyk-marynovanyi',
        ]];

        (new AdvanceEventCartRunJob($run->id, 0))->handle(
            new FakeCartAgent(new CartAgentPreparationData([]), [], $this->audit([], ['pork'], false)),
            $gateway,
            new CartQuantityCalculator,
            new GooseCartStatusService,
            new CartCandidateSuitability,
        );

        $run->refresh();
        $this->assertSame(CartRunPhase::Searching, $run->phase);
        $this->assertSame('свинина', data_get($run->state, 'needs.0.search_query'));
        $this->assertSame([], data_get($run->state, 'last_candidates'));
        Queue::assertPushed(AdvanceEventCartRunJob::class);
    }

    public function test_decision_audit_may_omit_previous_coverage_without_blocking_the_safe_selection(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $selectedNeed = [
            ...$this->waterNeed(),
            'key' => 'already-selected',
            'status' => 'selected',
            'selected_item' => ['need_key' => 'already-selected', 'product_id' => 'existing-choice'],
        ];
        $currentNeed = $this->waterNeed();
        $candidate = [
            'product_id' => 'water-1',
            'company_id' => 'company-1',
            'branch_id' => 'branch-1',
            'external_product_id' => 1,
            'name' => 'Вода негазована 2 л',
            'slug' => 'water-2l',
            'price' => 30.0,
            'old_price' => null,
            'stock' => 20.0,
            'available' => true,
            'image' => null,
            'weighted' => false,
            'step' => 1.0,
            'display_ratio' => '2 л',
            'special_prices' => [],
        ];
        $run = EventCartRun::factory()->for($event)->create([
            'phase' => CartRunPhase::Deciding,
            'status' => CartRunStatus::Running,
            'cursor' => 0,
            'plan_state_version' => $event->state_version,
            'cart_id' => $cart->cartId,
            'delivery_fingerprint' => $cart->fingerprint(),
            'cart_context' => $cart->toRunContext(),
            'state' => [
                'event_context' => $event->state,
                'plan_snapshot' => $event->shopping_plan,
                'needs' => [$selectedNeed, $currentNeed],
                'current_need_index' => 1,
                'last_candidates' => [$candidate],
                'last_details' => null,
            ],
            'staged_items' => [[
                'need_key' => 'already-selected',
                'product_id' => 'existing-choice',
                'estimated_total' => 10,
            ]],
        ]);
        $decision = new CartAgentDecisionData(
            action: 'select',
            selectedProductId: 'water-1',
            query: null,
            quantity: 2,
            reason: 'Поточна потреба безпечно покрита.',
            question: null,
            audit: $this->audit(covered: ['water'], remaining: [], complete: true),
        );

        (new AdvanceEventCartRunJob($run->id, 0))->handle(
            new FakeCartAgent(new CartAgentPreparationData([]), [$decision], $this->audit([], [], false)),
            new FakeCartGateway($cart),
            new CartQuantityCalculator,
            new GooseCartStatusService,
            new CartCandidateSuitability,
        );

        $run->refresh();
        $this->assertSame(CartRunPhase::Auditing, $run->phase);
        $this->assertSame(['existing-choice', 'water-1'], collect($run->staged_items)->pluck('product_id')->all());
    }

    public function test_final_audit_does_not_discard_a_safe_selected_fallback_merely_to_seek_an_exact_match(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $need = $this->waterNeed();
        $need['status'] = 'selected';
        $need['attempts'] = [['query' => 'вода питна', 'total_found' => 1]];
        $need['selected_item'] = [
            'need_key' => 'water',
            'product_id' => 'water-1',
            'match_evidence' => CartProductEvidence::MATCH_SAME_ROLE,
        ];
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
                'match_evidence' => CartProductEvidence::MATCH_SAME_ROLE,
            ]],
        ]);
        $audit = new CartAgentAuditData(
            complete: false,
            coveredNeedKeys: [],
            remainingNeedKeys: ['water'],
            enoughForPeople: false,
            warnings: [
                'water: рольова заміна потребує людської перевірки.',
                'water: не покрито, потрібен повторний пошук.',
            ],
            revisitNeedKey: 'water',
            revisitQuery: 'мінеральна вода',
            question: null,
        );

        (new AdvanceEventCartRunJob($run->id, 0))->handle(
            new FakeCartAgent(new CartAgentPreparationData([]), [], $audit),
            new FakeCartGateway($cart),
            new CartQuantityCalculator,
            new GooseCartStatusService,
            new CartCandidateSuitability,
        );

        $run->refresh();
        $this->assertSame(CartRunStatus::WaitingForConfirmation, $run->status);
        $this->assertSame(CartRunPhase::ReadyToCommit, $run->phase);
        $this->assertSame('selected', data_get($run->state, 'needs.0.status'));
        $this->assertSame(['water-1'], collect($run->staged_items)->pluck('product_id')->all());
        $this->assertSame(0, data_get($run->state, 'audit_revisits', 0));
        $this->assertTrue(data_get($run->state, 'final_audit.complete'));
        $this->assertSame(['water'], data_get($run->state, 'final_audit.covered_need_keys'));
        $this->assertSame([], data_get($run->state, 'final_audit.warnings'));
        Queue::assertNotPushed(AdvanceEventCartRunJob::class);
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
            'status' => CartRunStatus::Committing,
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

    public function test_commit_groups_one_reused_sku_into_one_absolute_cart_line(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $cart = $this->readyCart();
        $item = [
            'product_id' => 'shared-produce',
            'company_id' => 'company-1',
            'branch_id' => 'branch-1',
            'name' => 'Перець червоний',
            'price' => 99,
            'step' => 0.05,
            'stock' => 10,
        ];
        $run = EventCartRun::factory()->for($event)->create([
            'phase' => CartRunPhase::ReadyToCommit,
            'status' => CartRunStatus::Committing,
            'cursor' => 0,
            'plan_state_version' => $event->state_version,
            'cart_id' => $cart->cartId,
            'delivery_fingerprint' => $cart->fingerprint(),
            'cart_context' => $cart->toRunContext(),
            'state' => ['has_unmet_needs' => false],
            'staged_items' => [
                [...$item, 'need_key' => 'grill', 'quantity' => 1.5],
                [...$item, 'need_key' => 'salad', 'quantity' => 1.0],
            ],
        ]);
        $gateway = new FakeCartGateway($cart);

        (new CommitEventCartRunJob($run->id, 0))->handle($gateway, new GooseCartStatusService);

        $run->refresh();
        $this->assertSame(CartRunStatus::Synced, $run->status);
        $this->assertCount(1, $gateway->writes);
        $this->assertCount(1, $gateway->writes[0]);
        $this->assertSame('shared-produce', $gateway->writes[0][0]['productId']);
        $this->assertSame(2.5, $gateway->writes[0][0]['quantity']);
        $this->assertFalse($gateway->writes[0][0]['addQuantity']);
    }

    public function test_minimum_order_validation_does_not_block_writing_products_to_an_empty_cart(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        SilpoConnection::factory()->for($owner)->create(['access_token' => 'test-token']);
        $readyCart = $this->readyCart();
        $emptyCart = new SilpoCartContextData(
            cartId: $readyCart->cartId,
            deliveryType: $readyCart->deliveryType,
            branchId: $readyCart->branchId,
            companyId: $readyCart->companyId,
            slotStart: $readyCart->slotStart,
            slotEnd: $readyCart->slotEnd,
            items: [],
            validations: [[
                'level' => 'error',
                'type' => 'order',
                'message' => 'order.cost.min',
                'product_id' => null,
            ]],
            slot: $readyCart->slot,
            totalAfterDiscounts: 69,
            verifiedFulfilmentFingerprint: $readyCart->verifiedFulfilmentFingerprint,
        );
        $run = EventCartRun::factory()->for($event)->create([
            'phase' => CartRunPhase::ReadyToCommit,
            'status' => CartRunStatus::Committing,
            'cursor' => 0,
            'plan_state_version' => $event->state_version,
            'cart_id' => $emptyCart->cartId,
            'delivery_fingerprint' => $emptyCart->fingerprint(),
            'cart_context' => $emptyCart->toRunContext(),
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
        $gateway = new FakeCartGateway($emptyCart);
        $gateway->validationsAfterWrite = $emptyCart->validations;

        (new CommitEventCartRunJob($run->id, 0))->handle($gateway, new GooseCartStatusService);

        $run->refresh();
        $this->assertSame(CartRunStatus::Synced, $run->status);
        $this->assertSame(CartSyncStatus::Synced, $event->refresh()->cart_sync_status);
        $this->assertSame([], $run->warnings);
        $this->assertCount(1, $gateway->writes);
        $this->assertSame('water-1', $gateway->writes[0][0]['productId']);
        $this->assertSame(['water-1'], collect($gateway->cart?->items)->pluck('product_id')->all());
    }

    public function test_event_page_contains_the_large_live_cart_workspace(): void
    {
        [$owner, $event] = $this->eventWithPlan();

        $this->actingAs($owner)
            ->get(route('events.show', ['event' => $event, 'tab' => 'plan']))
            ->assertOk()
            ->assertSee('Скажіть Гусю, куди й як доставити')
            ->assertSee('Можна лишити нинішній маршрут або попросити інший. Спершу Гусь розбере фразу, потім Сільпо окремо підтвердить адресу, магазин і час.')
            ->assertSee('data-silpo-dialog', false)
            ->assertSee('data-silpo-dialog-minimize', false)
            ->assertSee('data-silpo-dialog-minimized', false)
            ->assertSee('data-silpo-dialog-restore', false)
            ->assertSee('Згорнути вікно кошика')
            ->assertSee('Розгорнути вікно кошика')
            ->assertSee('Перед походом у Сільпо')
            ->assertSee('Куди Гусю йти по кошик?')
            ->assertDontSee('Крок перед справжнім кошиком')
            ->assertDontSee('Спершу посадимо Гуся на правильний маршрут')
            ->assertDontSee('Маршрут людською мовою')
            ->assertSee('Гусю, маршрут є — лети збирати кошик')
            ->assertSee('data-silpo-steps', false)
            ->assertSee('data-silpo-confirm', false)
            ->assertSee('Тимчасовий кошик')
            ->assertSee('Останній людський погляд')
            ->assertSee('рольові заміни')
            ->assertSee('Підтверджую товари — додати в кошик')
            ->assertSee('Повний автопілот');

        $javascript = file_get_contents(resource_path('js/app.js'));
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString("silpoDialog.addEventListener('cancel'", $javascript);
        $this->assertStringContainsString('(! silpoDialog.open && ! harnessMinimized)', $javascript);
        $this->assertStringContainsString('pendingStagedRevealKeys', $javascript);
        $this->assertStringContainsString('stagedItemsContainer.scrollTo', $javascript);
        $this->assertStringNotContainsString("silpoDialog.close('cancel')", $javascript);
        $this->assertStringNotContainsString("silpoDialog.addEventListener('close', stopPolling)", $javascript);
        $this->assertStringContainsString('.staged-cart-item-arrival', $css);
    }

    public function test_silpo_tab_shows_verified_products_from_the_completed_cart(): void
    {
        [$owner, $event] = $this->eventWithPlan();
        EventCartRun::factory()->for($event)->create([
            'status' => CartRunStatus::Synced,
            'phase' => CartRunPhase::Finished,
            'state' => [
                'verified_cart' => [
                    'items' => [[
                        'product_id' => 'water-1',
                        'name' => 'Вода Моршинська негазована',
                        'image' => 'https://images.silpo.ua/v2/products/water.png',
                        'quantity' => 2,
                        'price' => 28.50,
                        'total' => 57,
                    ], [
                        'product_id' => 'personal-1',
                        'name' => 'Шоколад з особистого кошика',
                        'quantity' => 1,
                        'price' => 90,
                        'total' => 90,
                    ]],
                ],
            ],
            'staged_items' => [[
                'product_id' => 'water-1',
                'name' => 'Вода Моршинська негазована',
                'quantity' => 1,
                'price' => 28.50,
                'estimated_total' => 28.50,
            ], [
                'product_id' => 'water-1',
                'name' => 'Вода Моршинська негазована',
                'quantity' => 1,
                'price' => 28.50,
                'estimated_total' => 28.50,
            ]],
            'estimated_total' => 57,
            'actual_total' => 57,
            'finished_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('events.show', ['event' => $event, 'tab' => 'silpo']))
            ->assertOk()
            ->assertSee('Що Гусь додав')
            ->assertSee('Товарів у кошику: 1')
            ->assertSee('Вода Моршинська негазована')
            ->assertSee('https://images.silpo.ua/v2/products/water.png', escape: false)
            ->assertSee('2 × 28,50 ₴')
            ->assertSee('57,00 ₴')
            ->assertSee('Зібрати кошик наново')
            ->assertDontSee('Шоколад з особистого кошика')
            ->assertDontSee('2 позицій від Гуся');
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
        $this->actingAs($owner)
            ->postJson(route('events.cart-runs.confirm', [$otherEvent, $run]))
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

    /** @param array<int, array<string, mixed>> $validations */
    private function readyCart(array $validations = []): SilpoCartContextData
    {
        $slotStart = now()->addDay()->startOfHour()->toISOString();
        $slotEnd = now()->addDay()->startOfHour()->addHour()->toISOString();
        $address = [
            'addressType' => 'delivery',
            'address' => 'Київ, Хрещатик, 1',
            'city' => 'Київ',
            'street' => 'Хрещатик',
            'house' => '1',
            'latitude' => '50.4501',
            'longitude' => '30.5234',
        ];

        return new SilpoCartContextData(
            cartId: 'cart-1',
            deliveryType: 'DeliveryHome',
            branchId: 'branch-1',
            companyId: 'company-1',
            slotStart: $slotStart,
            slotEnd: $slotEnd,
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
            validations: $validations,
            slot: [
                'start' => now()->addDay()->startOfHour()->toISOString(),
                'end' => now()->addDay()->startOfHour()->addHour()->toISOString(),
                'deliveryCost' => 69,
                'minOrderCost' => 500,
            ],
            totalAfterDiscounts: 25,
            verifiedFulfilmentFingerprint: SilpoFulfilmentSnapshotData::selectionFingerprint([
                'cart_id' => 'cart-1',
                'delivery_type' => 'DeliveryHome',
                'address' => $address,
                'shipments' => [['companyId' => 'company-1', 'branchId' => 'branch-1']],
                'slot_start' => $slotStart,
                'slot_end' => $slotEnd,
            ]),
        );
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function routeIntentPayload(array $overrides = []): array
    {
        return [
            'action' => 'change',
            'address_query' => 'Київ, вул. Хрещатик, 1',
            'city' => 'Київ',
            'street' => 'вул. Хрещатик',
            'house' => '1',
            'delivery_preference' => 'unspecified',
            'nova_poshta_city' => null,
            'nova_poshta_office_hint' => null,
            'requested_local_date' => null,
            'requested_time_from' => null,
            'requested_time_to' => null,
            'needs_clarification' => false,
            'clarification_question' => null,
            ...$overrides,
        ];
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

final class FakeSilpoRouteIntentInterpreter implements SilpoRouteIntentInterpreter
{
    public function __construct(
        private readonly ?SilpoRouteIntentData $intent = null,
        private readonly ?RuntimeException $exception = null,
    ) {}

    public function interpret(
        string $sentence,
        CarbonImmutable $currentDate,
        string $timezone,
        ?HarnessRun $harnessRun = null,
    ): SilpoRouteIntentData {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->intent ?? throw new RuntimeException('Fake route intent is missing.');
    }
}

final class FakeCartGateway implements SilpoCartGateway
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $searchResults = [];

    /** @var array<int, string> */
    public array $searchQueries = [];

    /** @var array{categories: array<int, array<string, mixed>>, sets: array<int, array<string, mixed>>} */
    public array $catalogScopes = ['categories' => [], 'sets' => []];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $browseResults = [];

    /** @var array<int, array{type: string, slug: string}> */
    public array $browseScopes = [];

    /** @var array<int, int> */
    public array $browseLimits = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    public array $writes = [];

    /** @var array<int, array<string, mixed>> */
    public array $validationsAfterWrite = [];

    public ?SilpoCartRefreshCandidateData $refreshCandidate = null;

    public ?SilpoCartContextData $refreshResult = null;

    public int $refreshWrites = 0;

    public int $fulfilmentWrites = 0;

    /** @var array<string, mixed>|null */
    public ?array $lastFulfilmentAddress = null;

    /** @var array<int, array{companyId: string, branchId: string}>|null */
    public ?array $lastFulfilmentShipments = null;

    public ?string $lastTargetBranchId = null;

    public int $snapshotReads = 0;

    public int $deliveryTypeReads = 0;

    /** @var array<int, string> */
    public array $addressSearchQueries = [];

    /** @var array<int, string> */
    public array $novaPoshtaSettlementQueries = [];

    /** @var array<int, string|null> */
    public array $novaPoshtaOfficeQueries = [];

    /** @var array<int, array<string, mixed>> */
    public array $foundAddresses = [];

    /** @var array<int, array<string, mixed>>|null */
    public ?array $availableDeliveryTypes = null;

    /** @var array<int, array<string, mixed>>|null */
    public ?array $fulfilmentSlots = null;

    public bool $slotsAvailable = true;

    /** @var array<string, mixed> */
    public array $fulfilmentAddress = [
        'addressType' => 'delivery',
        'address' => 'Київ, Хрещатик, 1',
        'city' => 'Київ',
        'street' => 'Хрещатик',
        'house' => '1',
        'latitude' => '50.4501',
        'longitude' => '30.5234',
    ];

    /** @var array<string, mixed>|null */
    public ?array $fulfilmentReadbackAddress = null;

    /** @var array<int, array<string, mixed>>|null */
    public ?array $fulfilmentBranches = null;

    /** @var array<int, array<string, mixed>>|null */
    public ?array $savedDeliveryAddresses = null;

    public function __construct(public ?SilpoCartContextData $cart) {}

    public function getFulfilmentSnapshot(
        string $accessToken,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoFulfilmentSnapshotData {
        $this->snapshotReads++;

        if ($this->cart === null) {
            return null;
        }

        return new SilpoFulfilmentSnapshotData($this->cart->cartId, [
            'deliveryType' => $this->cart->deliveryType,
            'address' => $this->fulfilmentAddress,
            'timeslot' => [
                'start' => $this->cart->slotStart,
                'end' => $this->cart->slotEnd,
            ],
            'shipments' => [[
                'companyId' => $this->cart->companyId,
                'branchId' => $this->cart->branchId,
                'products' => collect($this->cart->items)->map(fn (array $item): array => [
                    'productId' => $item['product_id'],
                    'companyId' => $item['company_id'],
                    'branchId' => $item['branch_id'],
                    'quantity' => $item['quantity'],
                ])->all(),
            ]],
            'calculation' => [
                'totalAfterDiscounts' => $this->cart->totalAfterDiscounts,
                'validations' => $this->cart->validations,
            ],
        ]);
    }

    public function getSavedDeliveryAddresses(
        string $accessToken,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->savedDeliveryAddresses ?? [$this->fulfilmentAddress];
    }

    public function findDeliveryAddresses(
        string $accessToken,
        string $query,
        ?HarnessRun $harnessRun = null,
    ): array {
        $this->addressSearchQueries[] = $query;

        if ($this->foundAddresses !== []) {
            return $this->foundAddresses;
        }

        return [[
            'address' => $query,
            'city' => 'Київ',
            'street' => 'Хрещатик',
            'houseNumber' => '1',
            'latitude' => '50.4501',
            'longitude' => '30.5234',
        ]];
    }

    public function getAvailableDeliveryTypes(
        string $accessToken,
        float $latitude,
        float $longitude,
        ?HarnessRun $harnessRun = null,
    ): array {
        $this->deliveryTypeReads++;

        if ($this->availableDeliveryTypes !== null) {
            return $this->availableDeliveryTypes;
        }

        return [[
            'deliveryType' => 'DeliveryHome',
            'branchId' => 'branch-1',
            'description' => 'Доставка',
        ]];
    }

    public function getFulfilmentBranches(
        string $accessToken,
        bool $pickup,
        bool $novaPoshta,
        ?HarnessRun $harnessRun = null,
    ): array {
        if ($this->fulfilmentBranches !== null) {
            return $this->fulfilmentBranches;
        }

        return [[
            'branchId' => 'branch-1',
            'companyId' => 'company-1',
            'city' => 'Київ',
            'address' => 'вул. Хрещатик, 1',
            'latitude' => '50.4501',
            'longitude' => '30.5234',
            'open' => true,
        ]];
    }

    public function findNovaPoshtaSettlements(
        string $accessToken,
        string $query,
        ?HarnessRun $harnessRun = null,
    ): array {
        $this->novaPoshtaSettlementQueries[] = $query;

        return [['id' => 'kyiv', 'title' => 'Київ']];
    }

    public function findNovaPoshtaOffices(
        string $accessToken,
        string $settlementId,
        ?string $query = null,
        ?HarnessRun $harnessRun = null,
    ): array {
        $this->novaPoshtaOfficeQueries[] = $query;

        return [[
            'id' => 'office-1',
            'title' => 'Відділення №1',
            'number' => '1',
            'latitude' => '50.4501',
            'longitude' => '30.5234',
            'status' => 'Open',
        ]];
    }

    public function getFulfilmentSlots(
        string $accessToken,
        string $branchId,
        string $deliveryType,
        ?HarnessRun $harnessRun = null,
    ): array {
        if ($this->cart === null || ! $this->slotsAvailable) {
            return [];
        }

        if ($this->fulfilmentSlots !== null) {
            return $this->fulfilmentSlots;
        }

        return [[
            'start' => $this->cart->slotStart,
            'end' => $this->cart->slotEnd,
            'available' => true,
            'deliveryType' => $deliveryType,
            'deliveryCost' => 69,
            'minOrderCost' => 500,
        ]];
    }

    public function updateFulfilment(
        string $accessToken,
        string $cartId,
        string $deliveryType,
        string $slotStart,
        string $slotEnd,
        array $address,
        array $shipments,
        ?string $targetBranchId = null,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoFulfilmentSnapshotData {
        if ($this->cart === null || $this->cart->cartId !== $cartId) {
            return null;
        }

        $this->fulfilmentWrites++;
        $this->lastFulfilmentAddress = $address;
        $this->lastFulfilmentShipments = $shipments;
        $this->lastTargetBranchId = $targetBranchId;
        $effectiveAddress = $this->fulfilmentReadbackAddress ?? $address;
        $this->fulfilmentAddress = $effectiveAddress;
        $shipment = $shipments[0];

        if ($targetBranchId !== null) {
            $shipment['branchId'] = $targetBranchId;
        }

        $effectiveShipments = [$shipment];
        $this->cart = new SilpoCartContextData(
            cartId: $cartId,
            deliveryType: $deliveryType,
            branchId: $shipment['branchId'],
            companyId: $shipment['companyId'],
            slotStart: $slotStart,
            slotEnd: $slotEnd,
            items: $this->cart->items,
            validations: $this->cart->validations,
            slot: $this->cart->slot,
            totalAfterDiscounts: $this->cart->totalAfterDiscounts,
            verifiedFulfilmentFingerprint: SilpoFulfilmentSnapshotData::selectionFingerprint([
                'cart_id' => $cartId,
                'delivery_type' => $deliveryType,
                'address' => $effectiveAddress,
                'shipments' => $effectiveShipments,
                'slot_start' => $slotStart,
                'slot_end' => $slotEnd,
            ]),
        );

        return $this->getFulfilmentSnapshot($accessToken, $harnessRun);
    }

    public function getReadyCart(string $accessToken, ?HarnessRun $harnessRun = null): ?SilpoCartContextData
    {
        return $this->cart;
    }

    public function getCartRefreshCandidate(
        string $accessToken,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoCartRefreshCandidateData {
        return $this->refreshCandidate;
    }

    public function refreshCartTimeslot(
        string $accessToken,
        string $routeFingerprint,
        string $currentSlotFingerprint,
        string $slotStart,
        string $slotEnd,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoCartContextData {
        if ($this->cart?->slotStart === $slotStart && $this->cart?->slotEnd === $slotEnd) {
            return $this->cart;
        }

        if ($this->refreshCandidate === null
            || $routeFingerprint !== $this->refreshCandidate->routeFingerprint
            || $currentSlotFingerprint !== $this->refreshCandidate->currentSlotFingerprint
            || $slotStart !== $this->refreshCandidate->candidateSlotStart
            || $slotEnd !== $this->refreshCandidate->candidateSlotEnd) {
            return null;
        }

        $this->refreshWrites++;
        $this->cart = $this->refreshResult;

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

    public function getCatalogScopes(
        string $accessToken,
        SilpoCartContextData $cart,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->catalogScopes;
    }

    public function browseProducts(
        string $accessToken,
        SilpoCartContextData $cart,
        string $scopeType,
        string $scopeSlug,
        int $limit = 12,
        ?HarnessRun $harnessRun = null,
    ): array {
        $this->browseScopes[] = ['type' => $scopeType, 'slug' => $scopeSlug];
        $this->browseLimits[] = $limit;

        return $this->browseResults["{$scopeType}:{$scopeSlug}"] ?? [];
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
            validations: $this->validationsAfterWrite,
            slot: $this->cart->slot,
            totalAfterDiscounts: (float) $items->sum('total'),
            verifiedFulfilmentFingerprint: $this->cart->verifiedFulfilmentFingerprint,
        );

        return ['ok' => true];
    }
}
