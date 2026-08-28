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
    public function quantityFor(
        array $need,
        array $product,
        float $modelQuantity,
        bool $allowPartialStock = false,
    ): float {
        $step = max((float) data_get($product, 'step', 1), 0.001);
        $stock = (float) data_get($product, 'stock', 0);

        if ($stock <= 0 || data_get($product, 'available') === false) {
            throw new UnexpectedValueException('Selected product is out of stock.');
        }

        $quantity = $this->requiredQuantityFor($need, $product, $modelQuantity);

        if ($quantity > $stock + 0.0001 && $allowPartialStock) {
            $quantity = floor(($stock + 0.0001) / $step) * $step;
        }

        if ($quantity <= 0 || $quantity > $stock + 0.0001) {
            throw new UnexpectedValueException('Selected product quantity is not available.');
        }

        return round($quantity, 3);
    }

    /**
     * @param  array<string, mixed>  $need
     * @param  array<string, mixed>  $product
     */
    public function requiredQuantityFor(array $need, array $product, float $modelQuantity): float
    {
        $step = max((float) data_get($product, 'step', 1), 0.001);
        $quantity = $this->quantityFromPack($need, $product) ?? $modelQuantity;

        return round(ceil(($quantity - 0.0000001) / $step) * $step, 3);
    }

    /**
     * @param  array<string, mixed>  $need
     * @param  array<string, mixed>  $product
     */
    public function partialStockNote(
        array $need,
        array $product,
        float $modelQuantity,
        float $selectedQuantity,
    ): ?string {
        $requiredQuantity = $this->requiredQuantityFor($need, $product, $modelQuantity);

        if ($selectedQuantity + 0.0001 >= $requiredQuantity) {
            return null;
        }

        $requestedAmount = $this->formatAmount((float) data_get($need, 'quantity'));
        $requestedUnit = Str::squish((string) data_get($need, 'unit'));
        $availableAmount = $this->formatAmount($selectedQuantity);
        $availableUnit = data_get($product, 'weighted') === true ? 'кг' : 'шт.';
        $requested = trim("{$requestedAmount} {$requestedUnit}");

        return "⚠️ Залишок у Сільпо не покриває всю потребу «{$requested}»: Гусь додав доступний максимум {$availableAmount} {$availableUnit} після вичерпання повних альтернатив.";
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
    public function packageRoundingNote(array $need, array $product, float $quantity): ?string
    {
        $needAmount = $this->amountInBaseUnit((float) data_get($need, 'quantity'), (string) data_get($need, 'unit'));

        if ($needAmount === null
            || ! in_array($needAmount['group'], ['volume', 'weight'], true)
            || (bool) data_get($product, 'weighted', false)) {
            return null;
        }

        $displayRatio = (string) data_get($product, 'displayRatio', data_get($product, 'display_ratio', ''));
        $pack = $this->packAmount($displayRatio);

        if ($pack === null || $pack['group'] !== $needAmount['group']) {
            return null;
        }

        $actualAmount = $pack['amount'] * $quantity;

        if (abs($actualAmount - $needAmount['amount']) < 0.01) {
            return null;
        }

        $divisor = 1000;
        $unit = $needAmount['group'] === 'volume' ? 'л' : 'кг';
        $actual = $this->formatAmount($actualAmount / $divisor);
        $requested = $this->formatAmount($needAmount['amount'] / $divisor);
        $packages = $this->formatAmount($quantity);

        return "⚠️ Пакування {$displayRatio}: {$packages} шт. дають {$actual} {$unit} замість {$requested} {$unit}.";
    }

    /**
     * @param  array<string, mixed>  $need
     * @param  array<string, mixed>  $product
     */
    public function packageOverageInBaseUnits(array $need, array $product): ?float
    {
        $needAmount = $this->amountInBaseUnit((float) data_get($need, 'quantity'), (string) data_get($need, 'unit'));

        if ($needAmount === null || (bool) data_get($product, 'weighted', false)) {
            return $needAmount !== null && $needAmount['group'] === 'weight' ? 0.0 : null;
        }

        $pack = $this->packAmount((string) data_get($product, 'displayRatio', data_get($product, 'display_ratio', '')));

        if ($pack === null || $pack['group'] !== $needAmount['group'] || $pack['amount'] <= 0) {
            return null;
        }

        $packages = ceil($needAmount['amount'] / $pack['amount']);

        return max(0.0, ($packages * $pack['amount']) - $needAmount['amount']);
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

        if ($this->isPackageCountUnit((string) data_get($need, 'unit'))) {
            return ceil($needAmount['amount']);
        }

        if ((bool) data_get($product, 'weighted', false)) {
            return $needAmount['group'] === 'weight'
                ? $needAmount['amount'] / 1000
                : null;
        }

        $pack = $this->packAmount((string) data_get($product, 'displayRatio', data_get($product, 'display_ratio', '')));

        if (($pack === null || $pack['group'] !== $needAmount['group'])
            && $needAmount['group'] === 'count') {
            return ceil($needAmount['amount']);
        }

        if ($pack === null || $pack['group'] !== $needAmount['group'] || $pack['amount'] <= 0) {
            return null;
        }

        return ceil($needAmount['amount'] / $pack['amount']);
    }

    private function isPackageCountUnit(string $unit): bool
    {
        $normalizedUnit = Str::of($unit)->lower()->trim()->trim('.')->toString();

        return in_array($normalizedUnit, [
            'пачка', 'пачки', 'пачок',
            'упаковка', 'упаковки', 'упаковок',
            'банка', 'банки', 'банок',
            'пляшка', 'пляшки', 'пляшок',
            'пучок', 'пучки', 'пучків',
        ], true);
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
            'шт', 'штука', 'штуки', 'штук',
            'пачка', 'пачки', 'пачок',
            'упаковка', 'упаковки', 'упаковок',
            'банка', 'банки', 'банок',
            'пляшка', 'пляшки', 'пляшок',
            'пучок', 'пучки', 'пучків' => ['amount' => $quantity, 'group' => 'count'],
            default => null,
        };
    }

    private function formatAmount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 3, '.', ''), '0'), '.');
    }
}
