<?php

namespace App\Data;

use Illuminate\Support\Facades\Validator;
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
        $validated = Validator::make($payload, [
            'needs' => ['required', 'array', 'min:1', 'max:60'],
            'needs.*.key' => ['required', 'string', 'max:80', 'distinct'],
            'needs.*.source_index' => ['required', 'integer', 'min:0'],
            'needs.*.name' => ['required', 'string', 'max:255'],
            'needs.*.category' => ['required', 'in:food,water,soft_drinks,alcohol,supplies,other'],
            'needs.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'needs.*.unit' => ['required', 'string', 'max:100'],
            'needs.*.note' => ['present', 'string', 'max:1000'],
            'needs.*.search_query' => ['required', 'string', 'max:160'],
        ])->validate();

        $coveredIndexes = collect($validated['needs'])->pluck('source_index')->unique()->sort()->values();
        $expectedIndexes = collect(array_keys($planItems));
        $hasOverDecomposition = collect($validated['needs'])
            ->groupBy('source_index')
            ->contains(fn ($needs): bool => $needs->count() > 3);

        if ($coveredIndexes->diff($expectedIndexes)->isNotEmpty()
            || $expectedIndexes->diff($coveredIndexes)->isNotEmpty()
            || $hasOverDecomposition) {
            throw new UnexpectedValueException('Agent preparation did not preserve the approved shopping plan.');
        }

        $needs = collect($validated['needs'])
            ->map(fn (array $need): array => [
                ...$need,
                'quantity' => (float) $need['quantity'],
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
}
