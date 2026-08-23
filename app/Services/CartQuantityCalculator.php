<?php

namespace App\Services;

use Illuminate\Support\Str;
use UnexpectedValueException;

final class CartQuantityCalculator
{
    public function normalizeSearchQuery(string $query): string
    {
        $withoutPackaging = preg_replace(
            '/(?:^|\s)\d+(?:[.,]\d+)?\s*(?:кг|г|мг|л|мл|шт\.?|штук|пляш(?:ок|ки)?|бан(?:ок|ки)?|пач(?:ок|ки)?|уп\.?)(?=\s|$)/iu',
            ' ',
            $query,
        );

        return Str::of($withoutPackaging ?? $query)
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->limit(160, '')
            ->toString();
    }

    /**
     * @param  array<string, mixed>  $need
     * @param  array<string, mixed>  $product
     */
    public function quantityFor(array $need, array $product, float $modelQuantity): float
    {
        $step = max((float) data_get($product, 'step', 1), 0.001);
        $stock = (float) data_get($product, 'stock', 0);

        if ($stock <= 0 || data_get($product, 'available') === false) {
            throw new UnexpectedValueException('Selected product is out of stock.');
        }

        $quantity = $this->quantityFromPack($need, $product) ?? $modelQuantity;
        $quantity = ceil(($quantity - 0.0000001) / $step) * $step;
        $quantity = min($quantity, $stock);

        if ($quantity <= 0) {
            throw new UnexpectedValueException('Selected product quantity is not available.');
        }

        return round($quantity, 3);
    }

    /** @param array<string, mixed> $product */
    public function estimatedTotal(array $product, float $quantity): float
    {
        return round((float) data_get($product, 'price', 0) * $quantity, 2);
    }

    /**
     * @param  array<string, mixed>  $need
     * @param  array<string, mixed>  $product
     */
    private function quantityFromPack(array $need, array $product): ?float
    {
        $needAmount = $this->amountInBaseUnit((float) data_get($need, 'quantity'), (string) data_get($need, 'unit'));

        if ($needAmount === null) {
            return null;
        }

        if ((bool) data_get($product, 'weighted', false)) {
            return $needAmount['group'] === 'weight'
                ? $needAmount['amount'] / 1000
                : null;
        }

        $pack = $this->packAmount((string) data_get($product, 'displayRatio', data_get($product, 'display_ratio', '')));

        if ($pack === null || $pack['group'] !== $needAmount['group'] || $pack['amount'] <= 0) {
            return null;
        }

        return ceil($needAmount['amount'] / $pack['amount']);
    }

    /** @return array{amount: float, group: string}|null */
    private function packAmount(string $displayRatio): ?array
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(кг|г|л|мл|шт)/iu', $displayRatio, $matches) !== 1) {
            return null;
        }

        return $this->amountInBaseUnit(
            (float) str_replace(',', '.', $matches[1]),
            $matches[2],
        );
    }

    /** @return array{amount: float, group: string}|null */
    private function amountInBaseUnit(float $quantity, string $unit): ?array
    {
        $normalizedUnit = Str::of($unit)->lower()->trim()->trim('.')->toString();

        return match ($normalizedUnit) {
            'кг', 'кілограм', 'кілограми', 'кілограмів' => ['amount' => $quantity * 1000, 'group' => 'weight'],
            'г', 'грам', 'грами', 'грамів' => ['amount' => $quantity, 'group' => 'weight'],
            'л', 'літр', 'літри', 'літрів' => ['amount' => $quantity * 1000, 'group' => 'volume'],
            'мл', 'мілілітр', 'мілілітри', 'мілілітрів' => ['amount' => $quantity, 'group' => 'volume'],
            'шт', 'штука', 'штуки', 'штук', 'пачка', 'пачки', 'пачок', 'упаковка', 'упаковки', 'упаковок' => ['amount' => $quantity, 'group' => 'count'],
            default => null,
        };
    }
}
