<?php

namespace App\Actions;

use App\Contracts\SilpoCartGateway;
use App\Contracts\SilpoRouteIntentInterpreter;
use App\Data\SilpoFulfilmentSnapshotData;
use App\HarnessRunType;
use App\Models\Event;
use App\Models\HarnessRun;
use App\Models\User;
use App\Services\HarnessRecorder;
use App\Services\SilpoCartValidationPresenter;
use App\Services\SilpoFulfilmentTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DiscoverSilpoFulfilmentAction
{
    public function __construct(
        private readonly SilpoCartGateway $silpo,
        private readonly SilpoRouteIntentInterpreter $intentInterpreter,
        private readonly SilpoFulfilmentTokenService $tokens,
        private readonly HarnessRecorder $harnessRecorder,
        private readonly SilpoCartValidationPresenter $validationPresenter,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function execute(User $user, Event $event, array $input): array
    {
        $connection = $user->silpoConnection()->whereNull('revoked_at')->first();

        if ($connection === null || ($connection->expires_at !== null && $connection->expires_at->isPast())) {
            throw new RuntimeException('Звʼязок із Сільпо вже неактивний. Підключіть його ще раз.');
        }

        $harnessRun = $this->harnessRecorder->start(
            event: $event,
            type: HarnessRunType::SilpoPreflight,
            correlationId: (string) Str::ulid(),
            metadata: ['action' => 'fulfilment_'.$input['stage']],
        );

        try {
            $response = match ($input['stage']) {
                'intent' => $this->intent($user, $event, $connection->access_token, (string) $input['query'], $harnessRun),
                'address_search' => $this->addressSearch($user, $event, $connection->access_token, (string) $input['query'], $harnessRun),
                'address_options' => $this->addressOptions($user, $event, $connection->access_token, (string) $input['token'], $harnessRun),
                'nova_settlements' => $this->novaSettlements(
                    $user,
                    $event,
                    $connection->access_token,
                    (string) $input['token'],
                    (string) $input['query'],
                    $harnessRun,
                ),
                'nova_offices' => $this->novaOffices($user, $event, $connection->access_token, (string) $input['token'], $harnessRun),
                'nova_branches' => $this->novaBranches($user, $event, $connection->access_token, (string) $input['token'], $harnessRun),
                'slots' => $this->slots($user, $event, $connection->access_token, (string) $input['token'], $harnessRun),
                'review' => $this->review($user, $event, $connection->access_token, $input, $harnessRun),
                default => throw new RuntimeException('Гусь не впізнав цей крок маршруту.'),
            };
            $this->harnessRecorder->finish($harnessRun);

            return $response;
        } catch (Throwable $throwable) {
            $this->harnessRecorder->fail($harnessRun, $throwable);

            throw $throwable;
        }
    }

    /** @return array<string, mixed> */
    private function intent(
        User $user,
        Event $event,
        string $accessToken,
        string $sentence,
        HarnessRun $harnessRun,
    ): array {
        try {
            $intent = $this->intentInterpreter->interpret(
                $sentence,
                CarbonImmutable::now('Europe/Kyiv')->startOfDay(),
                'Europe/Kyiv',
                $harnessRun,
            );
        } catch (Throwable) {
            return $this->clarification(
                'Гусь не розібрав маршрут. Напишіть місто, вулицю, будинок і спосіб отримання одним реченням.',
            );
        }

        if ($intent->needsClarification) {
            return $this->clarification(
                $intent->clarificationQuestion ?? 'Уточніть адресу або спосіб отримання.',
            );
        }

        if ($intent->action === 'keep_current') {
            return [
                'kind' => 'keep_current',
                'heard' => $sentence,
                'message' => 'Гусь почув: нинішній маршрут лишається без змін.',
                'manual_fallback' => true,
            ];
        }

        if ($intent->deliveryPreference === 'nova_poshta') {
            $query = $intent->novaPoshtaQuery();

            if ($query === null) {
                return $this->clarification('У якому місті шукати відділення або поштомат Нової пошти?');
            }

            $snapshot = $this->snapshot($accessToken, $harnessRun);

            return [
                'kind' => 'nova_settlements',
                'heard' => $sentence,
                'office_hint' => $intent->novaPoshtaOfficeHint,
                'settlements' => $this->settlementOptions(
                    $user,
                    $event,
                    $snapshot,
                    $this->silpo->findNovaPoshtaSettlements($accessToken, $query, $harnessRun),
                    [
                        'delivery_preference' => $intent->deliveryPreference,
                        'time_preference' => $intent->timePreference(),
                        'office_hint' => $intent->novaPoshtaOfficeHint,
                    ],
                ),
                'manual_fallback' => true,
            ];
        }

        $snapshot = $this->snapshot($accessToken, $harnessRun);

        return [
            'kind' => 'address_candidates',
            'heard' => $sentence,
            'address_query' => $intent->addressQuery,
            'addresses' => $this->addressCandidates(
                $user,
                $event,
                $snapshot,
                $this->silpo->findDeliveryAddresses(
                    $accessToken,
                    (string) $intent->addressQuery,
                    $harnessRun,
                ),
                [
                    'delivery_preference' => $intent->deliveryPreference,
                    'time_preference' => $intent->timePreference(),
                ],
            ),
            'manual_fallback' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function addressSearch(
        User $user,
        Event $event,
        string $accessToken,
        string $query,
        HarnessRun $harnessRun,
    ): array {
        $snapshot = $this->snapshot($accessToken, $harnessRun);
        $addresses = $this->silpo->findDeliveryAddresses($accessToken, $query, $harnessRun);

        return [
            'kind' => 'address_candidates',
            'heard' => $query,
            'address_query' => $query,
            'addresses' => $this->addressCandidates($user, $event, $snapshot, $addresses),
            'manual_fallback' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function addressOptions(
        User $user,
        Event $event,
        string $accessToken,
        string $token,
        HarnessRun $harnessRun,
    ): array {
        $addressPayload = $this->tokens->decode($token, 'fulfilment_address', $user, $event);
        $address = Arr::get($addressPayload, 'address');

        if (! is_array($address) || ! $this->hasCoordinates($address)) {
            throw new RuntimeException('Гусь не зміг знайти координати цієї адреси.');
        }

        $snapshot = $this->snapshot($accessToken, $harnessRun);
        $this->guardCart($snapshot, (string) Arr::get($addressPayload, 'cart_id'));
        $deliveryPreference = (string) Arr::get($addressPayload, 'delivery_preference', 'unspecified');
        $timePreference = $this->timePreference($addressPayload);
        $latitude = (float) Arr::get($address, 'latitude');
        $longitude = (float) Arr::get($address, 'longitude');
        $types = $this->silpo->getAvailableDeliveryTypes(
            $accessToken,
            $latitude,
            $longitude,
            $harnessRun,
        );
        $branches = $this->silpo->getFulfilmentBranches(
            $accessToken,
            pickup: false,
            novaPoshta: false,
            harnessRun: $harnessRun,
        );
        $branchMap = collect($branches)->keyBy(fn (array $branch): string => (string) Arr::get($branch, 'branchId'));
        $options = collect($types)
            ->filter(fn (array $option): bool => in_array(
                Arr::get($option, 'deliveryType'),
                ['DeliveryHome', 'WideAssortDelivery'],
                true,
            ))
            ->map(function (array $option) use ($address, $addressPayload, $branchMap, $deliveryPreference, $event, $snapshot, $timePreference, $user): ?array {
                $branch = $branchMap->get((string) Arr::get($option, 'branchId'));

                if (! is_array($branch)) {
                    return null;
                }

                $homeWritable = Arr::get(
                    $addressPayload,
                    'home_writable',
                    Arr::get($addressPayload, 'writable'),
                ) === true;
                $route = [
                    'cart_id' => $snapshot->cartId,
                    'address' => $address,
                    'address_label' => (string) Arr::get($addressPayload, 'label'),
                    'address_source' => (string) Arr::get($addressPayload, 'address_source', 'found_coordinates'),
                    'delivery_type' => (string) Arr::get($option, 'deliveryType'),
                    'delivery_label' => $this->deliveryLabel((string) Arr::get($option, 'deliveryType')),
                    'target_branch_id' => (string) Arr::get($branch, 'branchId'),
                    'shipments' => [[
                        'companyId' => (string) Arr::get($branch, 'companyId'),
                        'branchId' => (string) Arr::get($branch, 'branchId'),
                    ]],
                    'branch_labels' => [$this->branchLabel($branch)],
                    'writable' => $homeWritable,
                    'message' => Arr::get($addressPayload, 'home_message'),
                    'unavailable_message' => Arr::get($addressPayload, 'home_unavailable_message'),
                    'delivery_preference' => $deliveryPreference,
                    'time_preference' => $timePreference,
                ];

                return $this->routeOption($user, $event, $route, $homeWritable);
            })
            ->filter()
            ->values();

        if (collect($types)->contains('deliveryType', 'SelfPickup')) {
            $pickupBranches = $this->silpo->getFulfilmentBranches(
                $accessToken,
                pickup: true,
                novaPoshta: false,
                harnessRun: $harnessRun,
            );

            $this->nearestBranches($pickupBranches, $latitude, $longitude)
                ->each(function (array $branch) use ($deliveryPreference, $event, $options, $snapshot, $timePreference, $user): void {
                    $route = [
                        'cart_id' => $snapshot->cartId,
                        'address' => $this->pickupAddress($branch),
                        'address_label' => $this->branchLabel($branch),
                        'address_source' => 'self_pickup',
                        'delivery_type' => 'SelfPickup',
                        'delivery_label' => 'Самовивіз',
                        'shipments' => [[
                            'companyId' => (string) Arr::get($branch, 'companyId'),
                            'branchId' => (string) Arr::get($branch, 'branchId'),
                        ]],
                        'branch_labels' => [$this->branchLabel($branch)],
                        'writable' => true,
                        'delivery_preference' => $deliveryPreference,
                        'time_preference' => $timePreference,
                    ];
                    $options->push($this->routeOption($user, $event, $route, true));
                });
        }

        if (collect($types)->contains('deliveryType', 'NovaPoshta')) {
            $options->push([
                'kind' => 'nova_poshta',
                'delivery_label' => 'Нова пошта',
                'description' => 'Оберіть місто, відділення або поштомат — Гусь покаже доступний час.',
                'writable' => true,
                'context_token' => $this->tokens->issue('fulfilment_np_context', $user, $event, [
                    'cart_id' => $snapshot->cartId,
                    'delivery_preference' => $deliveryPreference,
                    'time_preference' => $timePreference,
                ]),
                'preferred' => $deliveryPreference === 'nova_poshta',
            ]);
        }

        return [
            'options' => $options
                ->sortByDesc(fn (array $option): bool => Arr::get($option, 'preferred') === true)
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function novaSettlements(
        User $user,
        Event $event,
        string $accessToken,
        string $token,
        string $query,
        HarnessRun $harnessRun,
    ): array {
        $context = $this->tokens->decode($token, 'fulfilment_np_context', $user, $event);
        $snapshot = $this->snapshot($accessToken, $harnessRun);
        $this->guardCart($snapshot, (string) Arr::get($context, 'cart_id'));

        return [
            'settlements' => $this->settlementOptions(
                $user,
                $event,
                $snapshot,
                $this->silpo->findNovaPoshtaSettlements($accessToken, $query, $harnessRun),
                Arr::only($context, [
                    'delivery_preference',
                    'time_preference',
                    'office_hint',
                ]),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function novaOffices(
        User $user,
        Event $event,
        string $accessToken,
        string $token,
        HarnessRun $harnessRun,
    ): array {
        $payload = $this->tokens->decode($token, 'fulfilment_np_settlement', $user, $event);
        $settlement = Arr::get($payload, 'settlement');

        if (! is_array($settlement)) {
            throw new RuntimeException('Гусь загубив місто Нової пошти. Оберіть його ще раз.');
        }

        $settlementId = Arr::get($settlement, 'id');

        if (! is_string($settlementId) || $settlementId === '') {
            throw new RuntimeException('Гусь загубив місто Нової пошти. Оберіть його ще раз.');
        }

        return [
            'offices' => collect($this->silpo->findNovaPoshtaOffices(
                $accessToken,
                $settlementId,
                $this->nullableString(Arr::get($payload, 'office_hint')),
                $harnessRun,
            ))
                ->filter(fn (array $office): bool => data_get($office, 'status') !== 'Closed')
                ->take(30)
                ->map(fn (array $office): array => [
                    'label' => (string) Arr::get($office, 'title', Arr::get($office, 'address')),
                    'token' => $this->tokens->issue('fulfilment_np_office', $user, $event, [
                        'cart_id' => Arr::get($payload, 'cart_id'),
                        'settlement' => $settlement,
                        'office' => $office,
                        'delivery_preference' => Arr::get($payload, 'delivery_preference'),
                        'time_preference' => $this->timePreference($payload),
                    ]),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function novaBranches(
        User $user,
        Event $event,
        string $accessToken,
        string $token,
        HarnessRun $harnessRun,
    ): array {
        $payload = $this->tokens->decode($token, 'fulfilment_np_office', $user, $event);
        $settlement = Arr::get($payload, 'settlement');
        $office = Arr::get($payload, 'office');

        if (! is_array($settlement) || ! is_array($office)
            || $this->latitude($office) === null
            || $this->longitude($office) === null) {
            throw new RuntimeException('Гусь не зміг прочитати це відділення. Оберіть його ще раз.');
        }

        $snapshot = $this->snapshot($accessToken, $harnessRun);
        $this->guardCart($snapshot, (string) Arr::get($payload, 'cart_id'));
        $deliveryPreference = (string) Arr::get($payload, 'delivery_preference', 'nova_poshta');
        $timePreference = $this->timePreference($payload);
        $branches = $this->silpo->getFulfilmentBranches(
            $accessToken,
            pickup: false,
            novaPoshta: true,
            harnessRun: $harnessRun,
        );
        $address = $this->novaPoshtaAddress($settlement, $office);

        return [
            'options' => $this->nearestBranches(
                $branches,
                $this->latitude($office),
                $this->longitude($office),
            )->map(function (array $branch) use ($address, $deliveryPreference, $event, $office, $snapshot, $timePreference, $user): array {
                $route = [
                    'cart_id' => $snapshot->cartId,
                    'address' => $address,
                    'address_label' => (string) Arr::get($office, 'title', Arr::get($office, 'address')),
                    'address_source' => 'nova_poshta',
                    'delivery_type' => 'NovaPoshta',
                    'delivery_label' => 'Нова пошта',
                    'shipments' => [[
                        'companyId' => (string) Arr::get($branch, 'companyId'),
                        'branchId' => (string) Arr::get($branch, 'branchId'),
                    ]],
                    'branch_labels' => [$this->branchLabel($branch)],
                    'writable' => true,
                    'delivery_preference' => $deliveryPreference,
                    'time_preference' => $timePreference,
                ];

                return $this->routeOption($user, $event, $route, true);
            })->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function slots(
        User $user,
        Event $event,
        string $accessToken,
        string $token,
        HarnessRun $harnessRun,
    ): array {
        $route = $this->tokens->decode($token, 'fulfilment_route', $user, $event);
        $branchId = Arr::get($route, 'shipments.0.branchId');
        $deliveryType = Arr::get($route, 'delivery_type');

        if (! is_string($branchId) || $branchId === '' || ! is_string($deliveryType) || $deliveryType === '') {
            throw new RuntimeException('Гусь не знайшов магазин для цього маршруту.');
        }

        $timePreference = $this->timePreference($route);
        $slots = collect($this->silpo->getFulfilmentSlots($accessToken, $branchId, $deliveryType, $harnessRun))
            ->filter(fn (array $slot): bool => data_get($slot, 'available') === true)
            ->take(30)
            ->values();
        $recommendedStart = $this->recommendedSlotStart($slots, $timePreference);
        $hasTimePreference = collect($timePreference)->filter()->isNotEmpty();

        return [
            'route_token' => $token,
            'preference_note' => match (true) {
                $recommendedStart !== null => 'Гусь підсвітив найближчий час до вашого побажання. Остаточний вибір — лише після кліку.',
                $hasTimePreference => 'Точного збігу з побажанням немає, тож Гусь показує всі свіжі вікна Сільпо.',
                default => null,
            },
            'slots' => $slots
                ->map(fn (array $slot): array => [
                    'start' => (string) Arr::get($slot, 'start'),
                    'end' => (string) Arr::get($slot, 'end'),
                    'label' => $this->timeslotLabel(
                        (string) Arr::get($slot, 'start'),
                        (string) Arr::get($slot, 'end'),
                    ),
                    'delivery_cost' => Arr::get($slot, 'deliveryCost'),
                    'min_order_cost' => Arr::get($slot, 'minOrderCost'),
                    'recommended' => $recommendedStart !== null
                        && Arr::get($slot, 'start') === $recommendedStart,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function review(
        User $user,
        Event $event,
        string $accessToken,
        array $input,
        HarnessRun $harnessRun,
    ): array {
        $route = $this->tokens->decode((string) $input['token'], 'fulfilment_route', $user, $event);

        if (Arr::get($route, 'writable') !== true) {
            throw new RuntimeException('Нову домашню адресу Сільпо поки не дає записати безпечно. Оберіть збережену адресу, самовивіз або Нову пошту.');
        }

        $branchId = (string) Arr::get($route, 'shipments.0.branchId');
        $deliveryType = (string) Arr::get($route, 'delivery_type');
        $slot = collect($this->silpo->getFulfilmentSlots($accessToken, $branchId, $deliveryType, $harnessRun))
            ->first(fn (array $slot): bool => data_get($slot, 'available') === true
                && data_get($slot, 'start') === $input['slot_start']
                && data_get($slot, 'end') === $input['slot_end']);

        if (! is_array($slot)) {
            throw new RuntimeException('Цей час уже вислизнув. Гусь просить обрати інший.');
        }

        $snapshot = $this->snapshot($accessToken, $harnessRun);
        $this->guardCart($snapshot, (string) Arr::get($route, 'cart_id'));

        $homeAddress = Arr::get($route, 'address');
        $homeAddressSource = Arr::get($route, 'address_source');
        $homeRouteIsValid = $homeAddressSource === 'current_cart'
            ? $homeAddress === $snapshot->address()
            : $homeAddressSource === 'found_coordinates_flat'
                && is_array($homeAddress)
                && SilpoFulfilmentSnapshotData::hasMvpHomeAddressShape($homeAddress);

        if (in_array($deliveryType, ['DeliveryHome', 'WideAssortDelivery'], true) && ! $homeRouteIsValid) {
            throw new RuntimeException('Домашня адреса в кошику вже інша. Гусь просить звірити маршрут ще раз.');
        }

        $selection = [
            'cart_id' => $snapshot->cartId,
            'delivery_type' => $deliveryType,
            'address' => Arr::get($route, 'address'),
            'shipments' => Arr::get($route, 'shipments'),
            'address_source' => Arr::get($route, 'address_source'),
            'target_branch_id' => Arr::get($route, 'target_branch_id'),
            'slot_start' => $input['slot_start'],
            'slot_end' => $input['slot_end'],
        ];
        $summary = [
            'address_label' => (string) Arr::get($route, 'address_label'),
            'delivery_label' => (string) Arr::get($route, 'delivery_label'),
            'branch_labels' => Arr::get($route, 'branch_labels', []),
            'timeslot' => $this->timeslotLabel($input['slot_start'], $input['slot_end']),
            'shipments_count' => count(Arr::get($route, 'shipments', [])),
            'items_count' => $snapshot->itemsCount(),
            'total' => $snapshot->totalAfterDiscounts(),
            'validations' => $this->validationPresenter->present($snapshot->validations()),
        ];

        return [
            'review' => $summary,
            'review_token' => $this->tokens->issue('fulfilment_review', $user, $event, [
                'base_cart_fingerprint' => $snapshot->cartFingerprint(),
                'product_fingerprint' => $snapshot->productFingerprint(),
                'selection' => $selection,
                'summary' => $summary,
            ]),
        ];
    }

    private function snapshot(string $accessToken, HarnessRun $harnessRun): SilpoFulfilmentSnapshotData
    {
        $snapshot = $this->silpo->getFulfilmentSnapshot($accessToken, $harnessRun);

        if ($snapshot === null) {
            throw new RuntimeException('Кошик Сільпо кудись покотився. Створіть його й перевірте ще раз.');
        }

        return $snapshot;
    }

    private function guardCart(SilpoFulfilmentSnapshotData $snapshot, string $cartId): void
    {
        if ($cartId === '' || ! hash_equals($snapshot->cartId, $cartId)) {
            throw new RuntimeException('Кошик Сільпо змінився. Гусь просить почати перевірку маршруту ще раз.');
        }
    }

    /** @param array<int, array<string, mixed>> $addresses @param array<string, mixed> $metadata @return array<int, array<string, mixed>> */
    private function addressCandidates(
        User $user,
        Event $event,
        SilpoFulfilmentSnapshotData $snapshot,
        array $addresses,
        array $metadata = [],
    ): array {
        return collect($addresses)
            ->filter(fn (array $address): bool => $this->hasCoordinates($address))
            ->take(8)
            ->map(function (array $address) use ($event, $metadata, $snapshot, $user): array {
                $matchesCurrentHome = $snapshot->hasReusableHomeAddress()
                    && $this->sameHomeAddress($address, $snapshot->address());
                $mvpHomeAddress = $matchesCurrentHome ? null : $this->mvpHomeAddress($address);
                $homeWritable = $matchesCurrentHome || $mvpHomeAddress !== null;
                $resolvedAddress = $matchesCurrentHome ? $snapshot->address() : ($mvpHomeAddress ?? $address);

                return [
                    'label' => $this->addressLabel($address),
                    'writable' => $homeWritable,
                    'token' => $this->tokens->issue('fulfilment_address', $user, $event, [
                        'cart_id' => $snapshot->cartId,
                        'address' => $resolvedAddress,
                        'label' => $this->addressLabel($address),
                        'home_writable' => $homeWritable,
                        'address_source' => $matchesCurrentHome
                            ? 'current_cart'
                            : ($mvpHomeAddress !== null ? 'found_coordinates_flat' : 'found_coordinates'),
                        'home_message' => $mvpHomeAddress !== null
                            ? 'Гусь передасть Сільпо точну знайдену адресу як квартиру. Перед польотом ще раз звірте будинок і час.'
                            : null,
                        'home_unavailable_message' => $homeWritable
                            ? null
                            : 'Сільпо підтвердило точку, але не дало міста, вулиці або будинку. Гусь не буде домальовувати їх навмання.',
                        ...$metadata,
                    ]),
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<int, array<string, mixed>> $settlements @param array<string, mixed> $metadata @return array<int, array<string, mixed>> */
    private function settlementOptions(
        User $user,
        Event $event,
        SilpoFulfilmentSnapshotData $snapshot,
        array $settlements,
        array $metadata = [],
    ): array {
        return collect($settlements)
            ->take(10)
            ->map(fn (array $settlement): array => [
                'label' => collect([
                    Arr::get($settlement, 'title'),
                    Arr::get($settlement, 'area'),
                ])->filter()->implode(', '),
                'token' => $this->tokens->issue('fulfilment_np_settlement', $user, $event, [
                    'cart_id' => $snapshot->cartId,
                    'settlement' => $settlement,
                    ...$metadata,
                ]),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function clarification(string $question): array
    {
        return [
            'kind' => 'clarification',
            'question' => $question,
            'manual_fallback' => true,
        ];
    }

    /** @param array<string, mixed> $payload @return array{date: string|null, from: string|null, to: string|null} */
    private function timePreference(array $payload): array
    {
        $preference = Arr::get($payload, 'time_preference', []);

        return [
            'date' => $this->nullableString(Arr::get($preference, 'date')),
            'from' => $this->nullableString(Arr::get($preference, 'from')),
            'to' => $this->nullableString(Arr::get($preference, 'to')),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function preferredDeliveryType(string $preference): ?string
    {
        return match ($preference) {
            'home' => 'DeliveryHome',
            'wide_assortment' => 'WideAssortDelivery',
            'self_pickup' => 'SelfPickup',
            'nova_poshta' => 'NovaPoshta',
            default => null,
        };
    }

    /** @param Collection<int, array<string, mixed>> $slots @param array{date: string|null, from: string|null, to: string|null} $preference */
    private function recommendedSlotStart(Collection $slots, array $preference): ?string
    {
        if (collect($preference)->filter()->isEmpty()) {
            return null;
        }

        $timezone = (string) config('app.timezone');
        $slot = $slots->first(function (array $slot) use ($preference, $timezone): bool {
            $start = CarbonImmutable::parse((string) Arr::get($slot, 'start'))->setTimezone($timezone);
            $end = CarbonImmutable::parse((string) Arr::get($slot, 'end'))->setTimezone($timezone);

            return ($preference['date'] === null || $start->toDateString() === $preference['date'])
                && ($preference['from'] === null || $start->format('H:i') >= $preference['from'])
                && ($preference['to'] === null || $end->format('H:i') <= $preference['to']);
        });

        $start = Arr::get($slot, 'start');

        return is_string($start) && $start !== '' ? $start : null;
    }

    /** @param array<string, mixed> $route @return array<string, mixed> */
    private function routeOption(User $user, Event $event, array $route, bool $writable): array
    {
        $preferredDeliveryType = $this->preferredDeliveryType(
            (string) Arr::get($route, 'delivery_preference', 'unspecified'),
        );

        return [
            'kind' => 'route',
            'delivery_type' => $route['delivery_type'],
            'delivery_label' => $route['delivery_label'],
            'address_label' => $route['address_label'],
            'branch_label' => data_get($route, 'branch_labels.0'),
            'writable' => $writable,
            'message' => $writable
                ? $this->nullableString(Arr::get($route, 'message'))
                : (string) Arr::get(
                    $route,
                    'unavailable_message',
                    'Сільпо не дало всіх даних для домашнього запису. Гусь може лишити поточний маршрут, знайти самовивіз або Нову пошту.',
                ),
            'preferred' => $preferredDeliveryType !== null
                && Arr::get($route, 'delivery_type') === $preferredDeliveryType,
            'route_token' => $writable
                ? $this->tokens->issue('fulfilment_route', $user, $event, $route)
                : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     * @return Collection<int, array<string, mixed>>
     */
    private function nearestBranches(array $branches, float $latitude, float $longitude): Collection
    {
        return collect($branches)
            ->filter(fn (array $branch): bool => data_get($branch, 'open') !== false
                && is_numeric(Arr::get($branch, 'latitude'))
                && is_numeric(Arr::get($branch, 'longitude'))
                && filled(Arr::get($branch, 'branchId'))
                && filled(Arr::get($branch, 'companyId')))
            ->sortBy(fn (array $branch): float => $this->distance(
                $latitude,
                $longitude,
                (float) Arr::get($branch, 'latitude'),
                (float) Arr::get($branch, 'longitude'),
            ))
            ->take(5)
            ->values();
    }

    private function distance(float $latitude, float $longitude, float $otherLatitude, float $otherLongitude): float
    {
        $latitudeDelta = deg2rad($otherLatitude - $latitude);
        $longitudeDelta = deg2rad($otherLongitude - $longitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad($otherLatitude)) * sin($longitudeDelta / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** @param array<string, mixed> $candidate @param array<string, mixed> $current */
    private function sameHomeAddress(array $candidate, array $current): bool
    {
        return SilpoFulfilmentSnapshotData::representsSameHomeAddress($candidate, $current);
    }

    /** @param array<string, mixed> $address @return array<string, mixed>|null */
    private function mvpHomeAddress(array $address): ?array
    {
        $city = Arr::get($address, 'city');
        $street = Arr::get($address, 'street');
        $house = Arr::get($address, 'houseNumber', Arr::get($address, 'house'));

        if (! is_string($city) || $city === ''
            || ! is_string($street) || $street === ''
            || ! is_string($house) || $house === ''
            || ! $this->hasCoordinates($address)) {
            return null;
        }

        $resolved = [
            'addressType' => 'flat',
            'city' => $city,
            'street' => $street,
            'house' => $house,
            'latitude' => (string) Arr::get($address, 'latitude'),
            'longitude' => (string) Arr::get($address, 'longitude'),
        ];
        $district = Arr::get($address, 'district');

        if (is_string($district) && $district !== '') {
            $resolved['district'] = $district;
        }

        return $resolved;
    }

    /** @param array<string, mixed> $branch @return array<string, mixed> */
    private function pickupAddress(array $branch): array
    {
        $address = (string) Arr::get($branch, 'addressFull', Arr::get($branch, 'address'));

        return [
            'addressType' => 'self-pickup',
            'city' => (string) Arr::get($branch, 'cityFull', Arr::get($branch, 'city')),
            'locality' => $address,
            'street' => $address,
            'latitude' => (string) Arr::get($branch, 'latitude'),
            'longitude' => (string) Arr::get($branch, 'longitude'),
        ];
    }

    /** @param array<string, mixed> $settlement @param array<string, mixed> $office @return array<string, mixed> */
    private function novaPoshtaAddress(array $settlement, array $office): array
    {
        $officeType = Arr::get($office, 'type') === 'parcelLocker' ? 'Поштомат' : 'Відділення';

        return [
            'addressType' => 'nova-poshta',
            'city' => (string) Arr::get($settlement, 'title'),
            'region' => (string) Arr::get($settlement, 'area', Arr::get($settlement, 'region')),
            'latitude' => (string) $this->latitude($office),
            'longitude' => (string) $this->longitude($office),
            'officeId' => (string) Arr::get($office, 'id'),
            'street' => $officeType.' #'.Arr::get($office, 'number'),
        ];
    }

    /** @param array<string, mixed> $address */
    private function hasCoordinates(array $address): bool
    {
        return is_numeric(Arr::get($address, 'latitude')) && is_numeric(Arr::get($address, 'longitude'));
    }

    /** @param array<string, mixed> $value */
    private function latitude(array $value): ?float
    {
        $latitude = Arr::get($value, 'latitude', Arr::get($value, 'lat'));

        return is_numeric($latitude) ? (float) $latitude : null;
    }

    /** @param array<string, mixed> $value */
    private function longitude(array $value): ?float
    {
        $longitude = Arr::get($value, 'longitude', Arr::get($value, 'long'));

        return is_numeric($longitude) ? (float) $longitude : null;
    }

    /** @param array<string, mixed> $address */
    private function addressLabel(array $address): string
    {
        $direct = Arr::get($address, 'address');

        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        return (string) collect([
            Arr::get($address, 'city'),
            Arr::get($address, 'street'),
            Arr::get($address, 'houseNumber', Arr::get($address, 'house')),
        ])->filter()->unique()->implode(', ');
    }

    /** @param array<string, mixed> $branch */
    private function branchLabel(array $branch): string
    {
        return collect([
            Arr::get($branch, 'cityFull', Arr::get($branch, 'city')),
            Arr::get($branch, 'addressFull', Arr::get($branch, 'address')),
        ])->filter()->unique()->implode(', ');
    }

    private function deliveryLabel(string $deliveryType): string
    {
        return match ($deliveryType) {
            'DeliveryHome' => 'Доставка Сільпо',
            'WideAssortDelivery' => 'Доставка широкого асортименту',
            'SelfPickup' => 'Самовивіз',
            'NovaPoshta' => 'Нова пошта',
            default => 'Спосіб отримання Сільпо',
        };
    }

    private function timeslotLabel(string $start, string $end): string
    {
        $timezone = (string) config('app.timezone');

        return CarbonImmutable::parse($start)->setTimezone($timezone)->translatedFormat('j M, H:i')
            .'–'.CarbonImmutable::parse($end)->setTimezone($timezone)->format('H:i');
    }
}
