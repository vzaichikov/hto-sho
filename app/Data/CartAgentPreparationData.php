<?php

namespace App\Data;

use Illuminate\Support\Arr;
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
                    ->unique(fn (mixed $query): string => is_string($query)
                        ? Str::lower(trim($query))
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

        $deduplicatedNeeds = self::normalizePlanQuantities(
            self::deduplicateNeeds($validated['needs'], $planItems),
            $planItems,
        );
        $coveredIndexes = $deduplicatedNeeds->pluck('source_index')->unique()->sort()->values();
        $expectedIndexes = collect(array_keys($planItems));
        $hasOverDecomposition = $deduplicatedNeeds
            ->groupBy('source_index')
            ->contains(fn ($needs): bool => $needs->count() > 3);
        $groupCounts = $deduplicatedNeeds->countBy('source_index');
        $hasUnderDecomposedBroadNeed = collect($planItems)->contains(
            function (array $planItem, int $sourceIndex) use ($groupCounts): bool {
                return self::requiresMultipleSkuDecomposition($planItem)
                    && $groupCounts->get($sourceIndex, 0) < 2;
            },
        );

        if ($coveredIndexes->diff($expectedIndexes)->isNotEmpty()
            || $expectedIndexes->diff($coveredIndexes)->isNotEmpty()
            || $hasOverDecomposition
            || $hasUnderDecomposedBroadNeed) {
            throw new UnexpectedValueException('Agent preparation did not preserve the approved shopping plan.');
        }

        $needs = $deduplicatedNeeds
            ->values()
            ->map(fn (array $need, int $index): array => [
                ...$need,
                'key' => sprintf('n_%02d', $index + 1),
                'quantity' => (float) $need['quantity'],
                'search_query' => self::preferredInitialSearchQuery($need['search_queries']),
                'status' => 'pending',
                'attempts' => [],
                'inspected_products' => [],
                'selected_item' => null,
                'human_answer' => null,
            ])
            ->values()
            ->all();

        return new self($needs);
    }

    /**
     * Repair structurally valid model output only from the authoritative plan.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $planItems
     * @return array<string, mixed>
     */
    public static function repairAgainstPlan(array $payload, array $planItems): array
    {
        $deduplicatedNeeds = self::deduplicateNeeds(
            collect(data_get($payload, 'needs', []))
                ->filter(fn (mixed $need): bool => is_array($need))
                ->values()
                ->all(),
            $planItems,
        );
        $usedSemanticKeys = $deduplicatedNeeds
            ->mapWithKeys(fn (array $need): array => [self::semanticNeedKey($need) => true])
            ->all();

        foreach ($planItems as $sourceIndex => $planItem) {
            $requiredCount = self::requiresMultipleSkuDecomposition($planItem) ? 2 : 1;
            $existingNeeds = $deduplicatedNeeds->where('source_index', $sourceIndex);
            $missingCount = $requiredCount - $existingNeeds->count();

            if ($missingCount <= 0) {
                continue;
            }

            $planQuantity = (float) data_get($planItem, 'quantity', 1);
            $remainingQuantity = max(
                $planQuantity - $existingNeeds->sum(fn (array $need): float => (float) data_get($need, 'quantity', 0)),
                0,
            );
            $fallbackQuantity = $remainingQuantity > 0
                ? $remainingQuantity / $missingCount
                : $planQuantity / $requiredCount;
            $fallbackNames = self::fallbackNeedNames($planItem, $requiredCount + 3);

            foreach ($fallbackNames as $fallbackName) {
                if ($missingCount <= 0) {
                    break;
                }

                $fallbackNeed = self::fallbackNeed(
                    $planItem,
                    $sourceIndex,
                    $fallbackName,
                    $fallbackQuantity,
                );
                $semanticKey = self::semanticNeedKey($fallbackNeed);

                if (isset($usedSemanticKeys[$semanticKey])) {
                    continue;
                }

                $deduplicatedNeeds->push($fallbackNeed);
                $usedSemanticKeys[$semanticKey] = true;
                $missingCount--;
            }
        }

        return [
            'needs' => $deduplicatedNeeds
                ->sortBy(fn (array $need): int => (int) data_get($need, 'source_index'))
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $planItem */
    public static function requiresMultipleSkuDecomposition(array $planItem): bool
    {
        $name = Str::lower((string) data_get($planItem, 'name'));

        return (Str::contains($name, ['овоч'])
                && Str::contains($name, ['грил', 'салат']))
            || (Str::contains($name, [' та ', ' і '])
                && Str::contains($name, ['овоч', 'зелень', 'салат', 'фрукт']));
    }

    /**
     * @param  array<int, array<string, mixed>>  $needs
     * @param  array<int, array<string, mixed>>  $planItems
     */
    private static function deduplicateNeeds(array $needs, array $planItems): Collection
    {
        return collect($needs)
            ->values()
            ->map(fn (array $need, int $position): array => [
                ...$need,
                '_preparation_position' => $position,
            ])
            ->groupBy(fn (array $need): string => self::semanticNeedKey($need))
            ->map(function ($duplicates) use ($planItems): array {
                return $duplicates
                    ->sortBy(fn (array $need): array => [
                        self::requiresMultipleSkuDecomposition(
                            $planItems[(int) data_get($need, 'source_index')] ?? [],
                        ) ? 1 : 0,
                        (int) data_get($need, '_preparation_position'),
                    ])
                    ->first();
            })
            ->sortBy('_preparation_position')
            ->map(fn (array $need): array => Arr::except($need, '_preparation_position'))
            ->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $planItems
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

    /** @param array<string, mixed> $planItem @return array<int, string> */
    private static function fallbackNeedNames(array $planItem, int $limit): array
    {
        $name = trim((string) data_get($planItem, 'name', 'Позиція списку'));
        $components = preg_split('/\s+(?:та|і|й)\s+|[,\/]+/ui', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect([
            ...$components,
            $name,
            ...collect(range(2, $limit))->map(fn (int $variant): string => "{$name} — вид {$variant}"),
        ])
            ->map(fn (string $candidate): string => trim($candidate))
            ->filter(fn (string $candidate): bool => mb_strlen($candidate) >= 3)
            ->unique(fn (string $candidate): string => Str::lower($candidate))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $planItem
     * @return array<string, mixed>
     */
    private static function fallbackNeed(
        array $planItem,
        int $sourceIndex,
        string $name,
        float $quantity,
    ): array {
        $category = (string) data_get($planItem, 'category', 'other');

        if (! in_array($category, ['food', 'water', 'soft_drinks', 'alcohol', 'supplies', 'other'], true)) {
            $category = 'other';
        }

        $alternateQuery = trim($name.' '.($category === 'food' ? 'сирий' : 'товар'));

        return [
            'key' => 'repaired_'.$sourceIndex.'_'.Str::slug($name, '_'),
            'source_index' => $sourceIndex,
            'name' => $name,
            'category' => $category,
            'quantity' => max($quantity, 0.01),
            'unit' => (string) data_get($planItem, 'unit', 'шт'),
            'note' => (string) data_get($planItem, 'note', ''),
            'search_queries' => array_values(array_unique([$name, $alternateQuery])),
        ];
    }

    /** @param array<int, string> $queries */
    private static function preferredInitialSearchQuery(array $queries): string
    {
        $queries = collect($queries)
            ->map(fn (string $query): string => Str::squish($query))
            ->filter()
            ->unique(fn (string $query): string => Str::lower($query))
            ->values();
        $rootsByQuery = $queries->mapWithKeys(
            fn (string $query): array => [$query => self::searchIdentityRoots($query)],
        );
        $repeatedRoots = $rootsByQuery
            ->flatten()
            ->countBy()
            ->filter(fn (int $count): bool => $count >= 2)
            ->keys()
            ->values();

        if ($repeatedRoots->count() < 2) {
            return (string) $queries->first();
        }

        return (string) ($queries
            ->filter(function (string $query) use ($repeatedRoots, $rootsByQuery): bool {
                $queryRoots = collect($rootsByQuery->get($query, []));

                return $repeatedRoots->diff($queryRoots)->isEmpty();
            })
            ->sortBy(fn (string $query): array => [
                count($rootsByQuery->get($query, [])),
                mb_strlen($query),
            ])
            ->first() ?? $queries->first());
    }

    /** @return array<int, string> */
    private static function searchIdentityRoots(string $query): array
    {
        $positivePhrase = preg_split('/\b(?:без|крім|without)\b/ui', Str::lower($query), 2)[0] ?? '';
        $positivePhrase = str_replace('чіпс', 'чипс', $positivePhrase);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $positivePhrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($tokens)
            ->filter(fn (string $token): bool => mb_strlen($token) >= 4)
            ->map(fn (string $token): string => mb_substr($token, 0, 4))
            ->reject(fn (string $root): bool => in_array($root, [
                'альт', 'банк', 'бана', 'варі', 'гото', 'грил', 'кіло', 'літр',
                'паке', 'пачк', 'пози', 'прод', 'свіж', 'сири', 'смак', 'това',
                'упак', 'харч', 'част', 'штук',
            ], true))
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $need */
    private static function semanticNeedKey(array $need): string
    {
        $tokens = preg_split(
            '/[^\p{L}\p{N}]+/u',
            Str::lower((string) data_get($need, 'name')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $ignoredRoots = [
            'овоч', 'грил', 'свіж', 'сири', 'прод', 'набі', 'част', 'пози', 'соло', 'болг',
        ];
        $identityToken = collect($tokens)
            ->filter(fn (string $token): bool => mb_strlen($token) >= 4)
            ->first(fn (string $token): bool => ! in_array(mb_substr($token, 0, 4), $ignoredRoots, true));

        return $identityToken === null
            ? Str::lower(trim((string) data_get($need, 'name')))
            : mb_substr($identityToken, 0, 4);
    }
}
