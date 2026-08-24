<?php

namespace App\Actions;

use App\Contracts\SilpoCartGateway;
use App\Data\SilpoCartContextData;
use App\Data\SilpoFulfilmentSnapshotData;
use App\Exceptions\SilpoCartUnavailableException;
use App\HarnessRunType;
use App\Models\Event;
use App\Models\User;
use App\Services\HarnessRecorder;
use App\Services\SilpoFulfilmentTokenService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ConfirmSilpoFulfilmentAction
{
    public function __construct(
        private readonly SilpoCartGateway $silpo,
        private readonly SilpoFulfilmentTokenService $tokens,
        private readonly HarnessRecorder $harnessRecorder,
    ) {}

    public function execute(User $user, Event $event, string $reviewToken): SilpoCartContextData
    {
        $connection = $user->silpoConnection()->whereNull('revoked_at')->first();

        if ($connection === null || ($connection->expires_at !== null && $connection->expires_at->isPast())) {
            throw new SilpoCartUnavailableException(
                'connection_missing',
                'Гусь загубив звʼязок із Сільпо. Підключіть його ще раз.',
            );
        }

        try {
            return Cache::lock('silpo-fulfilment:user:'.$user->getKey(), 60)->block(
                2,
                fn (): SilpoCartContextData => $this->confirm(
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
    ): SilpoCartContextData {
        $payload = $this->tokens->decode($reviewToken, 'fulfilment_review', $user, $event);
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

            if (! $this->snapshotMatchesSelection($snapshot, $selection)) {
                $baseCartFingerprint = Arr::get($payload, 'base_cart_fingerprint');

                if (! is_string($baseCartFingerprint)
                    || ! hash_equals($baseCartFingerprint, $snapshot->cartFingerprint())) {
                    throw new RuntimeException('Маршрут кошика вже змінився. Гусь не буде перетирати його навмання.');
                }

                $usesCurrentHomeAddress = in_array(
                    Arr::get($selection, 'delivery_type'),
                    ['DeliveryHome', 'WideAssortDelivery'],
                    true,
                );
                $targetBranchId = $usesCurrentHomeAddress
                    ? Arr::get($selection, 'target_branch_id')
                    : null;
                $addressSource = Arr::get($selection, 'address_source');
                $selectedAddress = Arr::get($selection, 'address');
                $usesCurrentCartAddress = $addressSource === 'current_cart';
                $usesMvpHomeAddress = $addressSource === 'found_coordinates_flat'
                    && is_array($selectedAddress)
                    && SilpoFulfilmentSnapshotData::hasMvpHomeAddressShape($selectedAddress);

                if ($usesCurrentHomeAddress
                    && ((! $usesCurrentCartAddress && ! $usesMvpHomeAddress)
                        || ($usesCurrentCartAddress && $selectedAddress !== $snapshot->address())
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
                    address: $usesCurrentHomeAddress && $usesCurrentCartAddress
                        ? $snapshot->address()
                        : $selection['address'],
                    shipments: $usesCurrentHomeAddress ? $snapshot->routeShipments() : $selection['shipments'],
                    targetBranchId: $targetBranchId,
                    harnessRun: $harnessRun,
                );
            }

            if ($snapshot === null
                || ! $this->snapshotMatchesSelection($snapshot, $selection)
                || ! hash_equals($snapshot->productFingerprint(), $expectedProductFingerprint)) {
                throw new RuntimeException('Сільпо не підтвердило обраний маршрут. Гусь нічого далі не чіпає.');
            }

            $confirmedFulfilmentFingerprint = $snapshot->fulfilmentFingerprint();

            $cart = $this->silpo->getReadyCart($accessToken, $harnessRun);

            if ($cart === null || ! hash_equals($cart->fingerprint(), $confirmedFulfilmentFingerprint)) {
                throw new RuntimeException('Маршрут уже не готовий до походу. Гусь просить перевірити його ще раз.');
            }

            $this->harnessRecorder->finish($harnessRun);

            return $cart;
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
        if (Arr::get($selection, 'address_source') !== 'found_coordinates_flat') {
            return hash_equals(
                $snapshot->fulfilmentFingerprint(),
                SilpoFulfilmentSnapshotData::selectionFingerprint($selection),
            );
        }

        $selectedAddress = Arr::get($selection, 'address');

        return is_array($selectedAddress)
            && SilpoFulfilmentSnapshotData::hasMvpHomeAddressShape($selectedAddress)
            && Arr::get($snapshot->address(), 'addressType') === 'flat'
            && SilpoFulfilmentSnapshotData::representsSameHomeAddress($selectedAddress, $snapshot->address())
            && hash_equals($snapshot->cartId, (string) Arr::get($selection, 'cart_id'))
            && $snapshot->deliveryType() === Arr::get($selection, 'delivery_type')
            && $snapshot->routeShipments() === Arr::get($selection, 'shipments')
            && $snapshot->slotStart() === Arr::get($selection, 'slot_start')
            && $snapshot->slotEnd() === Arr::get($selection, 'slot_end');
    }
}
