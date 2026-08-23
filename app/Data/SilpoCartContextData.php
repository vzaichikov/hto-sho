<?php

namespace App\Data;

use Illuminate\Support\Arr;
use RuntimeException;

final readonly class SilpoCartContextData
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $validations
     * @param  array<string, mixed>  $slot
     */
    public function __construct(
        public string $cartId,
        public string $deliveryType,
        public string $branchId,
        public string $companyId,
        public string $slotStart,
        public string $slotEnd,
        public array $items,
        public array $validations,
        public array $slot,
        public ?float $totalAfterDiscounts,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $validatedSlot
     */
    public static function fromMcp(string $cartId, array $payload, array $validatedSlot): self
    {
        $cart = Arr::get($payload, 'cart');

        if (! is_array($cart)) {
            throw new RuntimeException('Сільпо повернуло кошик у неочікуваному форматі.');
        }

        $deliveryType = Arr::get($cart, 'deliveryType');
        $slotStart = Arr::get($cart, 'timeslot.start');
        $slotEnd = Arr::get($cart, 'timeslot.end');
        $branchId = Arr::get($cart, 'shipments.0.branchId');
        $companyId = Arr::get($cart, 'shipments.0.companyId');
        $address = Arr::get($cart, 'address');

        if (! is_string($deliveryType) || $deliveryType === ''
            || ! is_string($slotStart) || $slotStart === ''
            || ! is_string($slotEnd) || $slotEnd === ''
            || ! is_string($branchId) || $branchId === ''
            || ! is_string($companyId) || $companyId === ''
            || ! is_array($address) || $address === []) {
            throw new RuntimeException('Кошик Сільпо ще не має повного маршруту доставки.');
        }

        $items = collect(Arr::get($cart, 'shipments', []))
            ->flatMap(fn (mixed $shipment): array => is_array($shipment)
                ? Arr::get($shipment, 'products', [])
                : [])
            ->filter(fn (mixed $product): bool => is_array($product) && filled(Arr::get($product, 'productId')))
            ->map(fn (array $product): array => [
                'product_id' => (string) Arr::get($product, 'productId'),
                'company_id' => (string) Arr::get($product, 'companyId', $companyId),
                'branch_id' => (string) Arr::get($product, 'branchId', $branchId),
                'slug' => Arr::get($product, 'slug'),
                'name' => (string) Arr::get($product, 'name', 'Товар Сільпо'),
                'image' => Arr::get($product, 'image'),
                'display_ratio' => Arr::get($product, 'ratio'),
                'quantity' => (float) Arr::get($product, 'quantity', 0),
                'price' => (float) Arr::get($product, 'price', 0),
                'old_price' => Arr::get($product, 'oldPrice'),
                'stock' => (float) Arr::get($product, 'stock', 0),
                'weighted' => (bool) Arr::get($product, 'weighted', false),
                'step' => (float) Arr::get($product, 'addToBasketStep', 1),
                'total' => (float) Arr::get($product, 'total', 0),
                'source' => 'existing',
            ])
            ->values()
            ->all();

        $validations = collect(Arr::get($cart, 'calculation.validations', []))
            ->filter(fn (mixed $validation): bool => is_array($validation))
            ->map(fn (array $validation): array => [
                'level' => Arr::get($validation, 'level'),
                'type' => Arr::get($validation, 'type'),
                'message' => Arr::get($validation, 'message'),
                'product_id' => Arr::get($validation, 'context.productId'),
            ])
            ->values()
            ->all();

        return new self(
            cartId: $cartId,
            deliveryType: $deliveryType,
            branchId: $branchId,
            companyId: $companyId,
            slotStart: $slotStart,
            slotEnd: $slotEnd,
            items: $items,
            validations: $validations,
            slot: Arr::only($validatedSlot, [
                'start', 'end', 'deliveryType', 'deliveryCost', 'minOrderCost', 'maxWeight', 'constraints',
            ]),
            totalAfterDiscounts: is_numeric(Arr::get($cart, 'calculation.totalAfterDiscounts'))
                ? (float) Arr::get($cart, 'calculation.totalAfterDiscounts')
                : null,
        );
    }

    /** @param array<string, mixed> $context */
    public static function fromRunContext(array $context): self
    {
        return new self(
            cartId: (string) Arr::get($context, 'cart_id'),
            deliveryType: (string) Arr::get($context, 'delivery_type'),
            branchId: (string) Arr::get($context, 'branch_id'),
            companyId: (string) Arr::get($context, 'company_id'),
            slotStart: (string) Arr::get($context, 'slot_start'),
            slotEnd: (string) Arr::get($context, 'slot_end'),
            items: Arr::get($context, 'items', []),
            validations: Arr::get($context, 'validations', []),
            slot: Arr::get($context, 'slot', []),
            totalAfterDiscounts: is_numeric(Arr::get($context, 'total_after_discounts'))
                ? (float) Arr::get($context, 'total_after_discounts')
                : null,
        );
    }

    /** @return array<string, mixed> */
    public function toRunContext(): array
    {
        return [
            'cart_id' => $this->cartId,
            'delivery_type' => $this->deliveryType,
            'branch_id' => $this->branchId,
            'company_id' => $this->companyId,
            'slot_start' => $this->slotStart,
            'slot_end' => $this->slotEnd,
            'items' => $this->items,
            'validations' => $this->validations,
            'slot' => $this->slot,
            'total_after_discounts' => $this->totalAfterDiscounts,
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->cartId,
            $this->deliveryType,
            $this->branchId,
            $this->companyId,
            $this->slotStart,
            $this->slotEnd,
        ], JSON_THROW_ON_ERROR));
    }

    public function deliveryLabel(): string
    {
        return match ($this->deliveryType) {
            'DeliveryHome' => 'Доставка Сільпо',
            'WideAssortDelivery' => 'Доставка широкого асортименту',
            'NovaPoshta' => 'Нова пошта',
            'SelfPickup' => 'Самовивіз',
            default => 'Обраний спосіб Сільпо',
        };
    }
}
