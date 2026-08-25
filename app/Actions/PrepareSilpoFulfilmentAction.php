<?php

namespace App\Actions;

use App\Contracts\SilpoCartGateway;
use App\Data\SilpoFulfilmentSnapshotData;
use App\Exceptions\SilpoCartUnavailableException;
use App\HarnessRunType;
use App\Models\Event;
use App\Models\SilpoCartReset;
use App\Models\User;
use App\Services\HarnessRecorder;
use App\Services\SilpoCartResetGuard;
use App\Services\SilpoFulfilmentTokenService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

final class PrepareSilpoFulfilmentAction
{
    public function __construct(
        private readonly SilpoCartGateway $silpo,
        private readonly SilpoFulfilmentTokenService $tokens,
        private readonly HarnessRecorder $harnessRecorder,
        private readonly SilpoCartResetGuard $resetGuard,
    ) {}

    /** @return array<string, mixed> */
    public function execute(User $user, Event $event, SilpoCartReset $reset): array
    {
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

            $this->resetGuard->assertLatest($reset, $event);
            $this->resetGuard->assertEmptySnapshot($reset, $snapshot);
            $savedAddresses = $this->silpo->getSavedDeliveryAddresses(
                $connection->access_token,
                $harnessRun,
            );
            $response = $this->response($user, $event, $reset, $snapshot, $savedAddresses);
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
     * @param  array<int, array<string, mixed>>  $savedAddresses
     * @return array<string, mixed>
     */
    private function response(
        User $user,
        Event $event,
        SilpoCartReset $reset,
        SilpoFulfilmentSnapshotData $snapshot,
        array $savedAddresses,
    ): array {
        $addresses = collect();

        if ($snapshot->address() !== []) {
            $homeWritable = $snapshot->hasReusableHomeAddress();
            $addresses->push($this->addressOption(
                $user,
                $event,
                $snapshot,
                $snapshot->address(),
                $homeWritable,
                'Попередня адреса',
                'previous_cart',
                $homeWritable
                    ? null
                    : 'Цю адресу можна використати як точку пошуку, але місце, магазин і час треба обрати заново.',
                $reset,
            ));
        }

        collect($savedAddresses)
            ->map(fn (array $address): array => $this->unwrapAddress($address))
            ->filter(fn (array $address): bool => $this->hasCoordinates($address))
            ->each(function (array $address) use ($addresses, $event, $reset, $snapshot, $user): void {
                $addresses->push($this->addressOption(
                    $user,
                    $event,
                    $snapshot,
                    $address,
                    false,
                    'Збережена адреса',
                    'saved_address',
                    'Сільпо показало цю збережену адресу, але не дозволило перенести її в поточний кошик. Гусь може знайти від неї самовивіз або Нову пошту.',
                    $reset,
                ));
            });

        $addresses = $addresses
            ->unique(fn (array $address): string => hash('sha256', $address['label']))
            ->values();

        return [
            'ready' => true,
            'reset_verified' => true,
            'reset_token' => $this->resetGuard->issueToken($reset, $user, $event),
            'backup' => [
                'items_count' => $reset->items_count,
                'total' => $reset->total,
            ],
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
        bool $homeWritable,
        string $eyebrow,
        string $addressSource,
        ?string $homeUnavailableMessage,
        SilpoCartReset $reset,
    ): array {
        return [
            'eyebrow' => $eyebrow,
            'label' => $this->addressLabel($address),
            'writable' => $homeWritable,
            'token' => $this->tokens->issue('fulfilment_address', $user, $event, [
                'cart_id' => $snapshot->cartId,
                'address' => $address,
                'label' => $this->addressLabel($address),
                'home_writable' => $homeWritable,
                'address_source' => $addressSource,
                'home_unavailable_message' => $homeUnavailableMessage,
                ...$this->resetBinding($reset),
            ]),
        ];
    }

    /** @return array{reset_id: int, empty_product_fingerprint: string} */
    private function resetBinding(SilpoCartReset $reset): array
    {
        return [
            'reset_id' => $reset->id,
            'empty_product_fingerprint' => (string) $reset->empty_product_fingerprint,
        ];
    }

    /** @param array<string, mixed> $address */
    private function hasCoordinates(array $address): bool
    {
        return is_numeric(Arr::get($address, 'latitude')) && is_numeric(Arr::get($address, 'longitude'));
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
}
