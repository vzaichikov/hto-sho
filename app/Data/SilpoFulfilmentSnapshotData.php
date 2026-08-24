<?php

namespace App\Data;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class SilpoFulfilmentSnapshotData
{
    /** @param array<string, mixed> $cart */
    public function __construct(
        public string $cartId,
        public array $cart,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromMcp(string $cartId, array $payload): self
    {
        $cart = Arr::get($payload, 'cart');

        if (! is_array($cart)) {
            throw new RuntimeException('Сільпо повернуло кошик у неочікуваному форматі.');
        }

        return new self($cartId, $cart);
    }

    public function isComplete(): bool
    {
        return filled($this->deliveryType())
            && $this->address() !== []
            && $this->routeShipments() !== []
            && filled($this->slotStart())
            && filled($this->slotEnd());
    }

    public function hasReusableHomeAddress(): bool
    {
        return in_array($this->deliveryType(), ['DeliveryHome', 'WideAssortDelivery'], true)
            && filled(Arr::get($this->address(), 'addressType'))
            && is_string(Arr::get($this->address(), 'latitude'))
            && is_string(Arr::get($this->address(), 'longitude'))
            && is_numeric(Arr::get($this->address(), 'latitude'))
            && is_numeric(Arr::get($this->address(), 'longitude'));
    }

    /** @param array<string, mixed> $address */
    public static function hasMvpHomeAddressShape(array $address): bool
    {
        return Arr::get($address, 'addressType') === 'flat'
            && filled(Arr::get($address, 'city'))
            && filled(Arr::get($address, 'street'))
            && filled(Arr::get($address, 'house'))
            && is_string(Arr::get($address, 'latitude'))
            && is_string(Arr::get($address, 'longitude'))
            && is_numeric(Arr::get($address, 'latitude'))
            && is_numeric(Arr::get($address, 'longitude'));
    }

    /** @param array<string, mixed> $first @param array<string, mixed> $second */
    public static function representsSameHomeAddress(array $first, array $second): bool
    {
        $firstIdentity = self::homeAddressIdentity($first);
        $secondIdentity = self::homeAddressIdentity($second);

        if (in_array('', $firstIdentity, true)
            || $firstIdentity !== $secondIdentity
            || ! is_numeric(Arr::get($first, 'latitude'))
            || ! is_numeric(Arr::get($first, 'longitude'))
            || ! is_numeric(Arr::get($second, 'latitude'))
            || ! is_numeric(Arr::get($second, 'longitude'))) {
            return false;
        }

        $latitudeDelta = deg2rad((float) Arr::get($second, 'latitude') - (float) Arr::get($first, 'latitude'));
        $longitudeDelta = deg2rad((float) Arr::get($second, 'longitude') - (float) Arr::get($first, 'longitude'));
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad((float) Arr::get($first, 'latitude')))
            * cos(deg2rad((float) Arr::get($second, 'latitude')))
            * sin($longitudeDelta / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)) <= 0.2;
    }

    public function deliveryType(): ?string
    {
        $deliveryType = Arr::get($this->cart, 'deliveryType');

        return is_string($deliveryType) && $deliveryType !== '' ? $deliveryType : null;
    }

    /** @return array<string, mixed> */
    public function address(): array
    {
        $address = Arr::get($this->cart, 'address');

        return is_array($address) ? $address : [];
    }

    /** @return array<int, array{companyId: string, branchId: string}> */
    public function routeShipments(): array
    {
        return collect(Arr::get($this->cart, 'shipments', []))
            ->filter(fn (mixed $shipment): bool => is_array($shipment)
                && filled(Arr::get($shipment, 'companyId'))
                && filled(Arr::get($shipment, 'branchId')))
            ->map(fn (array $shipment): array => [
                'companyId' => (string) Arr::get($shipment, 'companyId'),
                'branchId' => (string) Arr::get($shipment, 'branchId'),
            ])
            ->values()
            ->all();
    }

    public function slotStart(): ?string
    {
        $start = Arr::get($this->cart, 'timeslot.start');

        return is_string($start) && $start !== '' ? $start : null;
    }

    public function slotEnd(): ?string
    {
        $end = Arr::get($this->cart, 'timeslot.end');

        return is_string($end) && $end !== '' ? $end : null;
    }

    public function itemsCount(): int
    {
        return collect(Arr::get($this->cart, 'shipments', []))
            ->sum(fn (mixed $shipment): int => is_array($shipment)
                ? count(Arr::get($shipment, 'products', []))
                : 0);
    }

    public function totalAfterDiscounts(): ?float
    {
        $total = Arr::get($this->cart, 'calculation.totalAfterDiscounts');

        return is_numeric($total) ? (float) $total : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function validations(): array
    {
        return collect(Arr::get($this->cart, 'calculation.validations', []))
            ->filter(fn (mixed $validation): bool => is_array($validation))
            ->values()
            ->all();
    }

    public function addressLabel(): string
    {
        $direct = Arr::get($this->address(), 'address');

        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        $parts = collect([
            Arr::get($this->address(), 'city'),
            Arr::get($this->address(), 'locality'),
            Arr::get($this->address(), 'street'),
            Arr::get($this->address(), 'house'),
        ])->filter(fn (mixed $part): bool => is_string($part) && $part !== '')->unique()->values();

        return $parts->isEmpty() ? 'Адреса Сільпо' : $parts->implode(', ');
    }

    /** @return array<string, mixed> */
    public function currentSelection(): array
    {
        if (! $this->isComplete()) {
            throw new RuntimeException('Кошик Сільпо ще не має повного маршруту отримання.');
        }

        return [
            'cart_id' => $this->cartId,
            'delivery_type' => $this->deliveryType(),
            'address' => $this->address(),
            'shipments' => $this->routeShipments(),
            'slot_start' => $this->slotStart(),
            'slot_end' => $this->slotEnd(),
        ];
    }

    public function cartFingerprint(): string
    {
        return self::hash([
            'cart_id' => $this->cartId,
            'delivery_type' => $this->deliveryType(),
            'address' => $this->address(),
            'timeslot' => ['start' => $this->slotStart(), 'end' => $this->slotEnd()],
            'shipments' => $this->normalizedShipmentsWithProducts(),
        ]);
    }

    public function productFingerprint(): string
    {
        return self::hash(
            collect(Arr::get($this->cart, 'shipments', []))
                ->filter(fn (mixed $shipment): bool => is_array($shipment))
                ->flatMap(fn (array $shipment): array => Arr::get($shipment, 'products', []))
                ->filter(fn (mixed $product): bool => is_array($product))
                ->map(fn (array $product): array => [
                    'productId' => (string) Arr::get($product, 'productId'),
                    'quantity' => (float) Arr::get($product, 'quantity', 0),
                ])
                ->sortBy(fn (array $product): string => $product['productId'].'|'.$product['quantity'])
                ->values()
                ->all(),
        );
    }

    /** @param array<string, mixed> $address @return array{city: string, street: string, house: string} */
    private static function homeAddressIdentity(array $address): array
    {
        return [
            'city' => self::normalizeAddressPart(Arr::get($address, 'city')),
            'street' => self::normalizeAddressPart(Arr::get($address, 'street'), stripStreetType: true),
            'house' => self::normalizeAddressPart(Arr::get($address, 'houseNumber', Arr::get($address, 'house'))),
        ];
    }

    private static function normalizeAddressPart(mixed $value, bool $stripStreetType = false): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        $normalized = Str::of($value)->lower();

        if ($stripStreetType) {
            $normalized = $normalized->replaceMatches(
                '/\b(?:вулиця|вул|проспект|просп|провулок|пров|площа|пл|дорога|дор)\.?\s*/u',
                '',
            );
        }

        return $normalized->replaceMatches('/[^\pL\pN]+/u', '')->toString();
    }

    public function fulfilmentFingerprint(): string
    {
        if (! $this->isComplete()) {
            return self::hash(['cart_id' => $this->cartId, 'incomplete' => true]);
        }

        return self::selectionFingerprint($this->currentSelection());
    }

    /** @param array<string, mixed> $selection */
    public static function selectionFingerprint(array $selection): string
    {
        return self::hash([
            'cart_id' => Arr::get($selection, 'cart_id'),
            'delivery_type' => Arr::get($selection, 'delivery_type'),
            'address' => Arr::get($selection, 'address', []),
            'shipments' => collect(Arr::get($selection, 'shipments', []))
                ->filter(fn (mixed $shipment): bool => is_array($shipment))
                ->map(fn (array $shipment): array => Arr::only($shipment, ['companyId', 'branchId']))
                ->values()
                ->all(),
            'timeslot' => [
                'start' => Arr::get($selection, 'slot_start'),
                'end' => Arr::get($selection, 'slot_end'),
            ],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizedShipmentsWithProducts(): array
    {
        return collect(Arr::get($this->cart, 'shipments', []))
            ->filter(fn (mixed $shipment): bool => is_array($shipment))
            ->map(fn (array $shipment): array => [
                'companyId' => (string) Arr::get($shipment, 'companyId'),
                'branchId' => (string) Arr::get($shipment, 'branchId'),
                'products' => collect(Arr::get($shipment, 'products', []))
                    ->filter(fn (mixed $product): bool => is_array($product))
                    ->map(fn (array $product): array => [
                        'productId' => (string) Arr::get($product, 'productId'),
                        'companyId' => (string) Arr::get($product, 'companyId'),
                        'branchId' => (string) Arr::get($product, 'branchId'),
                        'quantity' => (float) Arr::get($product, 'quantity', 0),
                    ])
                    ->sortBy('productId')
                    ->values()
                    ->all(),
            ])
            ->sortBy(fn (array $shipment): string => $shipment['companyId'].'|'.$shipment['branchId'])
            ->values()
            ->all();
    }

    /** @param array<string, mixed>|array<int, mixed> $value */
    private static function hash(array $value): string
    {
        return hash('sha256', json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => self::canonicalize($item), $value);
        }

        ksort($value);

        return collect($value)
            ->map(fn (mixed $item): mixed => self::canonicalize($item))
            ->all();
    }
}
