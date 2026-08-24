<?php

namespace App\Actions;

use App\CartRunStatus;
use App\Contracts\SilpoCartGateway;
use App\Data\SilpoFulfilmentSnapshotData;
use App\Exceptions\SilpoCartUnavailableException;
use App\HarnessRunType;
use App\Models\Event;
use App\Models\HarnessRun;
use App\Models\User;
use App\Services\HarnessRecorder;
use App\Services\SilpoCartValidationPresenter;
use App\Services\SilpoFulfilmentTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

final class PrepareSilpoFulfilmentAction
{
    public function __construct(
        private readonly SilpoCartGateway $silpo,
        private readonly SilpoFulfilmentTokenService $tokens,
        private readonly HarnessRecorder $harnessRecorder,
        private readonly SilpoCartValidationPresenter $validationPresenter,
    ) {}

    /** @return array<string, mixed> */
    public function execute(User $user, Event $event): array
    {
        $activeRun = $event->cartRuns()
            ->whereIn('status', $this->activeStatuses())
            ->latest()
            ->first();

        if ($activeRun !== null) {
            return [
                'ready' => true,
                'active_run_url' => route('events.cart-runs.show', [$event, $activeRun]),
            ];
        }

        $connection = $user->silpoConnection()->whereNull('revoked_at')->first();

        if ($connection === null || ($connection->expires_at !== null && $connection->expires_at->isPast())) {
            throw new SilpoCartUnavailableException(
                'connection_missing',
                'Гусь загубив звʼязок із Сільпо. Підключіть його ще раз.',
            );
        }

        $harnessRun = $this->harnessRecorder->start(
            event: $event,
            type: HarnessRunType::SilpoPreflight,
            correlationId: (string) Str::ulid(),
            metadata: ['action' => 'fulfilment_preflight'],
        );

        try {
            $snapshot = $this->silpo->getFulfilmentSnapshot($connection->access_token, $harnessRun);

            if ($snapshot === null) {
                $this->harnessRecorder->finish($harnessRun);

                throw new SilpoCartUnavailableException(
                    'cart_missing',
                    'У Сільпо ще немає кошика для Гуся. Відкрийте Сільпо, створіть кошик і повертайтеся.',
                );
            }

            $branches = $this->silpo->getFulfilmentBranches(
                $connection->access_token,
                pickup: false,
                novaPoshta: false,
                harnessRun: $harnessRun,
            );
            $savedAddresses = $this->silpo->getSavedDeliveryAddresses(
                $connection->access_token,
                $harnessRun,
            );
            $response = $this->response($user, $event, $snapshot, $branches, $savedAddresses, $connection->access_token, $harnessRun);
            $this->harnessRecorder->finish($harnessRun);

            return $response;
        } catch (Throwable $throwable) {
            if ($harnessRun->finished_at === null) {
                $this->harnessRecorder->fail($harnessRun, $throwable);
            }

            throw $throwable;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     * @param  array<int, array<string, mixed>>  $savedAddresses
     * @return array<string, mixed>
     */
    private function response(
        User $user,
        Event $event,
        SilpoFulfilmentSnapshotData $snapshot,
        array $branches,
        array $savedAddresses,
        string $accessToken,
        HarnessRun $harnessRun,
    ): array {
        $branchMap = collect($branches)->keyBy(fn (array $branch): string => (string) Arr::get($branch, 'branchId'));
        $addresses = collect();

        if ($snapshot->address() !== []) {
            $addresses->push($this->addressOption($user, $event, $snapshot, $snapshot->address(), true, 'Поточна адреса'));
        }

        collect($savedAddresses)
            ->map(fn (array $address): array => $this->unwrapAddress($address))
            ->filter(fn (array $address): bool => $this->hasCoordinates($address))
            ->each(function (array $address) use ($addresses, $event, $snapshot, $user): void {
                $addresses->push($this->addressOption(
                    $user,
                    $event,
                    $snapshot,
                    $address,
                    $this->isWritableAddress($address),
                    'Збережена адреса',
                ));
            });

        $addresses = $addresses
            ->unique(fn (array $address): string => hash('sha256', $address['label']))
            ->values();
        $current = null;

        if ($snapshot->isComplete()) {
            $selection = $snapshot->currentSelection();
            $branchLabels = collect($snapshot->routeShipments())
                ->map(fn (array $shipment): string => $this->branchLabel(
                    $branchMap->get($shipment['branchId']),
                ))
                ->values()
                ->all();
            $routePayload = [
                'cart_id' => $snapshot->cartId,
                'address' => $snapshot->address(),
                'address_label' => $snapshot->addressLabel(),
                'delivery_type' => $snapshot->deliveryType(),
                'delivery_label' => $this->deliveryLabel((string) $snapshot->deliveryType()),
                'shipments' => $snapshot->routeShipments(),
                'branch_labels' => $branchLabels,
                'writable' => true,
            ];
            $slots = in_array($snapshot->deliveryType(), [
                'DeliveryHome',
                'WideAssortDelivery',
                'NovaPoshta',
                'SelfPickup',
            ], true)
                ? $this->silpo->getFulfilmentSlots(
                    $accessToken,
                    $snapshot->routeShipments()[0]['branchId'],
                    (string) $snapshot->deliveryType(),
                    $harnessRun,
                )
                : [];
            $currentSlot = collect($slots)->first(fn (array $slot): bool => data_get($slot, 'available') === true
                && data_get($slot, 'start') === $snapshot->slotStart()
                && data_get($slot, 'end') === $snapshot->slotEnd());
            $reviewToken = is_array($currentSlot)
                ? $this->reviewToken($user, $event, $snapshot, $selection, $routePayload)
                : null;

            $current = [
                'address_label' => $snapshot->addressLabel(),
                'delivery_label' => $routePayload['delivery_label'],
                'branch_labels' => $branchLabels,
                'timeslot' => $this->timeslotLabel((string) $snapshot->slotStart(), (string) $snapshot->slotEnd()),
                'slot_valid' => is_array($currentSlot),
                'items_count' => $snapshot->itemsCount(),
                'total' => $snapshot->totalAfterDiscounts(),
                'shipments_count' => count($snapshot->routeShipments()),
                'validations' => $this->validationPresenter->present($snapshot->validations()),
                'route_token' => $this->tokens->issue('fulfilment_route', $user, $event, $routePayload),
                'review_token' => $reviewToken,
            ];
        }

        return [
            'ready' => true,
            'current' => $current,
            'addresses' => $addresses->all(),
            'discover_url' => route('events.silpo.fulfilment.discover', $event),
            'start_url' => route('events.cart-runs.store', $event),
        ];
    }

    /** @return array<string, mixed> */
    private function addressOption(
        User $user,
        Event $event,
        SilpoFulfilmentSnapshotData $snapshot,
        array $address,
        bool $writable,
        string $eyebrow,
    ): array {
        return [
            'eyebrow' => $eyebrow,
            'label' => $this->addressLabel($address),
            'writable' => $writable,
            'token' => $this->tokens->issue('fulfilment_address', $user, $event, [
                'cart_id' => $snapshot->cartId,
                'address' => $address,
                'label' => $this->addressLabel($address),
                'writable' => $writable,
            ]),
        ];
    }

    /** @param array<string, mixed> $selection @param array<string, mixed> $routePayload */
    private function reviewToken(
        User $user,
        Event $event,
        SilpoFulfilmentSnapshotData $snapshot,
        array $selection,
        array $routePayload,
    ): string {
        return $this->tokens->issue('fulfilment_review', $user, $event, [
            'base_cart_fingerprint' => $snapshot->cartFingerprint(),
            'product_fingerprint' => $snapshot->productFingerprint(),
            'selection' => $selection,
            'summary' => Arr::only($routePayload, [
                'address_label', 'delivery_label', 'branch_labels',
            ]),
        ]);
    }

    /** @param array<string, mixed> $address */
    private function hasCoordinates(array $address): bool
    {
        return is_numeric(Arr::get($address, 'latitude')) && is_numeric(Arr::get($address, 'longitude'));
    }

    /** @param array<string, mixed> $address */
    private function isWritableAddress(array $address): bool
    {
        return filled(Arr::get($address, 'addressType'))
            && is_string(Arr::get($address, 'latitude'))
            && is_string(Arr::get($address, 'longitude'))
            && $this->hasCoordinates($address);
    }

    /** @param array<string, mixed> $address */
    private function unwrapAddress(array $address): array
    {
        $nested = Arr::get($address, 'address');

        return is_array($nested)
            ? [...Arr::except($address, ['address']), ...$nested]
            : $address;
    }

    /** @param array<string, mixed> $address */
    private function addressLabel(array $address): string
    {
        $direct = Arr::get($address, 'address');

        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        return collect([
            Arr::get($address, 'city'),
            Arr::get($address, 'locality'),
            Arr::get($address, 'street'),
            Arr::get($address, 'house'),
            Arr::get($address, 'houseNumber'),
        ])->filter(fn (mixed $part): bool => is_string($part) && $part !== '')->unique()->implode(', ');
    }

    /** @param array<string, mixed>|null $branch */
    private function branchLabel(?array $branch): string
    {
        if ($branch === null) {
            return 'Магазин Сільпо';
        }

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
            'NovaPoshta' => 'Нова пошта',
            'SelfPickup' => 'Самовивіз',
            default => 'Спосіб отримання Сільпо',
        };
    }

    private function timeslotLabel(string $start, string $end): string
    {
        $timezone = (string) config('app.timezone');

        return CarbonImmutable::parse($start)->setTimezone($timezone)->translatedFormat('j M, H:i')
            .'–'.CarbonImmutable::parse($end)->setTimezone($timezone)->format('H:i');
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
