<?php

namespace App\Actions;

use App\CartRunStatus;
use App\Contracts\SilpoCartGateway;
use App\Data\ConfirmedSilpoFulfilmentData;
use App\Data\SilpoFulfilmentSnapshotData;
use App\Exceptions\SilpoCartUnavailableException;
use App\HarnessRunType;
use App\Models\Event;
use App\Models\User;
use App\Services\HarnessRecorder;
use App\Services\SilpoCartLock;
use App\Services\SilpoCartResetGuard;
use App\Services\SilpoFulfilmentTokenService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ConfirmSilpoFulfilmentAction
{
    public function __construct(
        private readonly SilpoCartGateway $silpo,
        private readonly SilpoFulfilmentTokenService $tokens,
        private readonly HarnessRecorder $harnessRecorder,
        private readonly SilpoCartResetGuard $resetGuard,
        private readonly SilpoCartLock $lock,
    ) {}

    public function execute(User $user, Event $event, string $reviewToken): ConfirmedSilpoFulfilmentData
    {
        $connection = $user->silpoConnection()->whereNull('revoked_at')->first();

        if ($connection === null || ($connection->expires_at !== null && $connection->expires_at->isPast())) {
            throw new SilpoCartUnavailableException(
                'connection_missing',
                'Гусь загубив звʼязок із Сільпо. Підключіть його ще раз.',
            );
        }

        try {
            return $this->lock->execute(
                $user->id,
                fn (): ConfirmedSilpoFulfilmentData => $this->confirm(
                    $user,
                    $event,
                    $connection->access_token,
                    $reviewToken,
                ),
            );
        } catch (LockTimeoutException) {
            throw new RuntimeException('Гусь уже перевіряє цей кошик. Дайте йому мить і натисніть ще раз.');
        }
    }

    private function confirm(
        User $user,
        Event $event,
        string $accessToken,
        string $reviewToken,
    ): ConfirmedSilpoFulfilmentData {
        if ($user->cartRuns()->whereIn('event_cart_runs.status', $this->activeStatuses())->exists()) {
            throw new RuntimeException('Інший похід Гуся вже працює з кошиком. Завершіть його перед новим маршрутом.');
        }

        $payload = $this->tokens->decode($reviewToken, 'fulfilment_review', $user, $event);
        $reset = $this->resetGuard->fromPayload($payload, $user, $event);
        $selection = Arr::get($payload, 'selection');

        if (! is_array($selection)) {
            throw new RuntimeException('Гусь загубив обраний маршрут. Перевірте його ще раз.');
        }

        $this->guardSelection($selection);
        $harnessRun = $this->harnessRecorder->start(
            event: $event,
            type: HarnessRunType::SilpoPreflight,
            correlationId: (string) Str::ulid(),
            metadata: ['action' => 'fulfilment_confirm'],
        );

        try {
            $snapshot = $this->silpo->getFulfilmentSnapshot($accessToken, $harnessRun);

            if ($snapshot === null || ! hash_equals($snapshot->cartId, (string) $selection['cart_id'])) {
                throw new RuntimeException('Кошик Сільпо змінився. Гусь просить перевірити маршрут ще раз.');
            }

            $this->resetGuard->assertEmptySnapshot($reset, $snapshot);

            $expectedProductFingerprint = Arr::get($payload, 'product_fingerprint');

            if (! is_string($expectedProductFingerprint)
                || ! hash_equals($expectedProductFingerprint, $snapshot->productFingerprint())) {
                throw new RuntimeException('Товари в кошику Сільпо змінилися. Гусь просить перевірити маршрут ще раз.');
            }

            $slotIsAvailable = collect($this->silpo->getFulfilmentSlots(
                $accessToken,
                (string) Arr::get($selection, 'shipments.0.branchId'),
                (string) $selection['delivery_type'],
                $harnessRun,
            ))->contains(fn (array $slot): bool => data_get($slot, 'available') === true
                && data_get($slot, 'start') === $selection['slot_start']
                && data_get($slot, 'end') === $selection['slot_end']);

            if (! $slotIsAvailable) {
                throw new RuntimeException('Обраний час уже вислизнув. Гусь нічого не змінював — оберіть інший.');
            }

            $baseCartFingerprint = Arr::get($payload, 'base_cart_fingerprint');

            if (! is_string($baseCartFingerprint)) {
                throw new RuntimeException('Маршрут кошика вже змінився. Гусь не буде перетирати його навмання.');
            }

            $baseCartIsCurrent = hash_equals($baseCartFingerprint, $snapshot->cartFingerprint());
            $selectionIsAlreadyApplied = ! $baseCartIsCurrent
                && $this->snapshotMatchesSelection($snapshot, $selection);

            if (! $baseCartIsCurrent && ! $selectionIsAlreadyApplied) {
                throw new RuntimeException('Маршрут кошика вже змінився. Гусь не буде перетирати його навмання.');
            }

            if (! $selectionIsAlreadyApplied) {
                $usesHomeAddress = in_array(
                    Arr::get($selection, 'delivery_type'),
                    ['DeliveryHome', 'WideAssortDelivery'],
                    true,
                );
                $targetBranchId = $usesHomeAddress ? Arr::get($selection, 'target_branch_id') : null;
                $addressSource = Arr::get($selection, 'address_source');
                $selectedAddress = Arr::get($selection, 'address');
                $usesPreviousCartAddress = in_array($addressSource, ['current_cart', 'previous_cart'], true);
                $usesMvpHomeAddress = $addressSource === 'found_coordinates_flat'
                    && is_array($selectedAddress)
                    && SilpoFulfilmentSnapshotData::hasMvpHomeAddressShape($selectedAddress);

                if ($usesHomeAddress
                    && ((! $usesPreviousCartAddress && ! $usesMvpHomeAddress)
                        || ($usesPreviousCartAddress && $selectedAddress !== $snapshot->address())
                        || ! is_string($targetBranchId)
                        || $targetBranchId === '')) {
                    throw new RuntimeException('Домашня адреса вже не збігається з кошиком. Гусь нічого не записував.');
                }

                $snapshot = $this->silpo->updateFulfilment(
                    accessToken: $accessToken,
                    cartId: (string) $selection['cart_id'],
                    deliveryType: (string) $selection['delivery_type'],
                    slotStart: (string) $selection['slot_start'],
                    slotEnd: (string) $selection['slot_end'],
                    address: $usesPreviousCartAddress ? $snapshot->address() : $selection['address'],
                    shipments: $selection['shipments'],
                    targetBranchId: $targetBranchId,
                    harnessRun: $harnessRun,
                );

                if ($snapshot === null
                    || ! $this->snapshotMatchesSelection($snapshot, $selection)
                    || ! hash_equals($snapshot->productFingerprint(), $expectedProductFingerprint)) {
                    throw new RuntimeException('Сільпо не підтвердило обраний маршрут. Гусь нічого далі не чіпає.');
                }
            }

            $this->resetGuard->assertEmptySnapshot($reset, $snapshot);

            $confirmedFulfilmentFingerprint = $snapshot->fulfilmentFingerprint();

            $cart = $this->silpo->getReadyCart($accessToken, $harnessRun);

            if ($cart === null
                || $cart->items !== []
                || ! hash_equals($cart->fingerprint(), $confirmedFulfilmentFingerprint)) {
                throw new RuntimeException('Маршрут уже не готовий до походу. Гусь просить перевірити його ще раз.');
            }

            $this->harnessRecorder->finish($harnessRun);

            return new ConfirmedSilpoFulfilmentData($cart, $reset);
        } catch (Throwable $throwable) {
            $this->harnessRecorder->fail($harnessRun, $throwable);

            throw $throwable;
        }
    }

    /** @param array<string, mixed> $selection */
    private function guardSelection(array $selection): void
    {
        $shipments = Arr::get($selection, 'shipments');

        if (! filled(Arr::get($selection, 'cart_id'))
            || ! in_array(Arr::get($selection, 'delivery_type'), [
                'DeliveryHome',
                'WideAssortDelivery',
                'SelfPickup',
                'NovaPoshta',
            ], true)
            || ! is_array(Arr::get($selection, 'address'))
            || Arr::get($selection, 'address') === []
            || ! is_array($shipments)
            || $shipments === []
            || collect($shipments)->contains(fn (mixed $shipment): bool => ! is_array($shipment)
                || blank(Arr::get($shipment, 'companyId'))
                || blank(Arr::get($shipment, 'branchId')))
            || ! filled(Arr::get($selection, 'slot_start'))
            || ! filled(Arr::get($selection, 'slot_end'))) {
            throw new RuntimeException('Гусь не зміг прочитати обраний маршрут. Перевірте його ще раз.');
        }
    }

    /** @param array<string, mixed> $selection */
    private function snapshotMatchesSelection(SilpoFulfilmentSnapshotData $snapshot, array $selection): bool
    {
        $addressSource = Arr::get($selection, 'address_source');
        $selectedAddress = Arr::get($selection, 'address');

        if (! is_array($selectedAddress)
            || ! hash_equals($snapshot->cartId, (string) Arr::get($selection, 'cart_id'))
            || $snapshot->deliveryType() !== Arr::get($selection, 'delivery_type')
            || $snapshot->routeShipments() !== Arr::get($selection, 'shipments')
            || $snapshot->slotStart() !== Arr::get($selection, 'slot_start')
            || $snapshot->slotEnd() !== Arr::get($selection, 'slot_end')) {
            return false;
        }

        if ($addressSource === 'found_coordinates_flat') {
            return SilpoFulfilmentSnapshotData::hasMvpHomeAddressShape($selectedAddress)
                && Arr::get($snapshot->address(), 'addressType') === 'flat'
                && SilpoFulfilmentSnapshotData::representsSameHomeAddress($selectedAddress, $snapshot->address());
        }

        if (in_array($addressSource, ['self_pickup', 'nova_poshta'], true)) {
            $actualAddress = $snapshot->address();

            return collect($selectedAddress)->every(
                fn (mixed $value, string|int $key): bool => array_key_exists($key, $actualAddress)
                    && $actualAddress[$key] === $value,
            );
        }

        return $snapshot->address() === $selectedAddress;
    }

    /** @return array<int, string> */
    private function activeStatuses(): array
    {
        return [
            CartRunStatus::Running->value,
            CartRunStatus::WaitingForAnswer->value,
            CartRunStatus::WaitingForConfirmation->value,
            CartRunStatus::Committing->value,
        ];
    }
}
