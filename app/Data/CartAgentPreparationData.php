<?php

namespace App\Data;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use UnexpectedValueException;

final readonly class CartAgentPreparationData
{
    /** @param array<int, array<string, mixed>> $needs */
    public function __construct(public array $needs) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $planItems
     */
    public static function from(array $payload, array $planItems): self
    {
        $payload['needs'] = collect(data_get($payload, 'needs', []))
            ->map(function (mixed $need): mixed {
                if (! is_array($need) || ! is_array(data_get($need, 'search_queries'))) {
                    return $need;
                }

                $need['search_queries'] = collect($need['search_queries'])
                    ->map(fn (mixed $query): mixed => is_string($query) ? Str::squish($query) : $query)
                    ->unique(fn (mixed $query): string => is_string($query)
                        ? Str::lower($query)
                        : serialize($query))
                    ->values()
                    ->all();

                return $need;
            })
            ->all();

        $validated = Validator::make($payload, [
            'needs' => ['required', 'array', 'min:1', 'max:60'],
            'needs.*.key' => ['required', 'string', 'max:80'],
            'needs.*.source_index' => ['required', 'integer', 'min:0'],
            'needs.*.name' => ['required', 'string', 'max:255'],
            'needs.*.category' => ['required', 'in:food,water,soft_drinks,alcohol,supplies,other'],
            'needs.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'needs.*.unit' => ['required', 'string', 'max:100'],
            'needs.*.note' => ['present', 'string', 'max:1000'],
            'needs.*.search_queries' => ['required', 'array', 'min:2', 'max:6'],
            'needs.*.search_queries.*' => ['required', 'string', 'max:160'],
        ])->validate();

        $needs = collect($validated['needs'])->values();
        self::assertExactProductNamesAreUnique($needs);
        self::assertPlanCoverage($needs, $planItems);

        $needs = self::normalizePlanQuantities($needs, $planItems)
            ->values()
            ->map(fn (array $need, int $index): array => [
                ...$need,
                'key' => sprintf('n_%02d', $index + 1),
                'quantity' => (float) $need['quantity'],
                'optional' => (bool) data_get(
                    $planItems,
                    ((int) $need['source_index']).'.optional',
                    false,
                ),
                'search_query' => (string) $need['search_queries'][0],
                'status' => 'pending',
                'attempts' => [],
                'inspected_products' => [],
                'selected_item' => null,
                'human_answer' => null,
            ])
            ->all();

        return new self($needs);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $needs
     * @param  array<int, array<string, mixed>>  $planItems
     */
    private static function assertPlanCoverage(Collection $needs, array $planItems): void
    {
        $coveredIndexes = $needs->pluck('source_index')->unique()->sort()->values();
        $expectedIndexes = collect(array_keys($planItems))->sort()->values();

        if ($coveredIndexes->diff($expectedIndexes)->isNotEmpty()
            || $expectedIndexes->diff($coveredIndexes)->isNotEmpty()) {
            throw new UnexpectedValueException(sprintf(
                'Preparation source indexes must exactly cover [%s]; received [%s].',
                $expectedIndexes->implode(', '),
                $coveredIndexes->implode(', '),
            ));
        }

        $counts = $needs->countBy('source_index');

        foreach ($planItems as $sourceIndex => $planItem) {
            $minimum = (int) data_get($planItem, 'minimum_distinct_products', 1);
            $actual = (int) $counts->get($sourceIndex, 0);

            if ($minimum < 1 || $minimum > 3) {
                throw new UnexpectedValueException(sprintf(
                    'Plan item %d has invalid minimum_distinct_products %d.',
                    $sourceIndex,
                    $minimum,
                ));
            }

            if ($actual < $minimum || $actual > 3) {
                throw new UnexpectedValueException(sprintf(
                    'Plan item %d requires %d to 3 distinct products; received %d.',
                    $sourceIndex,
                    $minimum,
                    $actual,
                ));
            }
        }
    }

    /** @param Collection<int, array<string, mixed>> $needs */
    private static function assertExactProductNamesAreUnique(Collection $needs): void
    {
        $duplicateNames = $needs
            ->groupBy(fn (array $need): string => Str::lower(Str::squish((string) $need['name'])))
            ->filter(fn (Collection $duplicates): bool => $duplicates->count() > 1)
            ->keys();

        if ($duplicateNames->isNotEmpty()) {
            throw new UnexpectedValueException(sprintf(
                'Preparation contains duplicate product names: %s.',
                $duplicateNames->implode(', '),
            ));
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $needs
     * @param  array<int, array<string, mixed>>  $planItems
     * @return Collection<int, array<string, mixed>>
     */
    private static function normalizePlanQuantities(Collection $needs, array $planItems): Collection
    {
        return $needs
            ->groupBy('source_index')
            ->flatMap(function (Collection $sourceNeeds, int|string $sourceIndex) use ($planItems): array {
                $planItem = $planItems[(int) $sourceIndex] ?? null;
                $planQuantity = data_get($planItem, 'quantity');

                if (! is_array($planItem) || ! is_numeric($planQuantity) || (float) $planQuantity <= 0) {
                    return $sourceNeeds->values()->all();
                }

                $targetQuantity = (float) $planQuantity;
                $currentTotal = $sourceNeeds->sum(
                    fn (array $need): float => (float) data_get($need, 'quantity', 0),
                );
                $remainingQuantity = $targetQuantity;
                $lastIndex = $sourceNeeds->count() - 1;

                return $sourceNeeds
                    ->values()
                    ->map(function (array $need, int $index) use (
                        $currentTotal,
                        $lastIndex,
                        $planItem,
                        &$remainingQuantity,
                        $targetQuantity,
                    ): array {
                        $quantity = $index === $lastIndex
                            ? $remainingQuantity
                            : ($currentTotal > 0
                                ? $targetQuantity * ((float) data_get($need, 'quantity', 0) / $currentTotal)
                                : $targetQuantity / ($lastIndex + 1));
                        $remainingQuantity -= $quantity;

                        return [
                            ...$need,
                            'quantity' => max($quantity, 0.01),
                            'unit' => (string) data_get($planItem, 'unit', data_get($need, 'unit')),
                        ];
                    })
                    ->all();
            })
            ->values();
    }
}
