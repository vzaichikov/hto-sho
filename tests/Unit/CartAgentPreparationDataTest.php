<?php

namespace Tests\Unit;

use App\Data\CartAgentPreparationData;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use UnexpectedValueException;

class CartAgentPreparationDataTest extends TestCase
{
    public function test_it_replaces_model_authored_keys_with_stable_opaque_keys(): void
    {
        $preparation = CartAgentPreparationData::from([
            'needs' => [
                $this->need('need_svyny_na_shashlyk', 0, 'свинина на шашлик'),
                $this->need('need_steyky_telyatyna', 1, 'стейки з телятини'),
            ],
        ], [
            ['name' => 'свинина на шашлик'],
            ['name' => 'стейки з телятини'],
        ]);

        $this->assertSame(['n_01', 'n_02'], array_column($preparation->needs, 'key'));
    }

    public function test_it_replaces_repeated_model_keys_from_independent_batches(): void
    {
        $preparation = CartAgentPreparationData::from([
            'needs' => [
                $this->need('n_01', 0, 'свинина на шашлик'),
                $this->need('n_01', 1, 'стейки з телятини'),
            ],
        ], [
            ['name' => 'свинина на шашлик'],
            ['name' => 'стейки з телятини'],
        ]);

        $this->assertSame(['n_01', 'n_02'], array_column($preparation->needs, 'key'));
    }

    public function test_it_accepts_agent_decomposition_of_a_broad_grill_vegetable_need(): void
    {
        $preparation = CartAgentPreparationData::from([
            'needs' => [
                [...$this->need('vegetables-a', 0, 'овоч А для гриля'), 'quantity' => 1.5],
                [...$this->need('vegetables-b', 0, 'овоч Б для гриля'), 'quantity' => 1.5],
            ],
        ], [[
            'name' => 'овочі для гриля',
            'note' => 'Суттєва вегетаріанська частина меню.',
        ]]);

        $this->assertSame(
            ['овоч А для гриля', 'овоч Б для гриля'],
            array_column($preparation->needs, 'name'),
        );
        $this->assertSame([1.5, 1.5], array_column($preparation->needs, 'quantity'));
        $this->assertSame(['n_01', 'n_02'], array_column($preparation->needs, 'key'));
    }

    public function test_it_rejects_an_under_decomposed_broad_grill_vegetable_need(): void
    {
        $this->expectException(UnexpectedValueException::class);

        CartAgentPreparationData::from([
            'needs' => [$this->need('vegetables', 0, 'овочі для гриля')],
        ], [[
            'name' => 'овочі для гриля',
            'note' => 'Суттєва вегетаріанська частина меню.',
        ]]);
    }

    public function test_it_rejects_duplicate_search_queries_in_application_validation(): void
    {
        $need = $this->need('pork', 0, 'свинина на шашлик');
        $need['search_queries'] = ['свинина', 'свинина'];

        $this->expectException(ValidationException::class);

        CartAgentPreparationData::from(['needs' => [$need]], [
            ['name' => 'свинина на шашлик'],
        ]);
    }

    public function test_it_allows_different_needs_to_share_a_useful_broad_search_query(): void
    {
        $firstNeed = $this->need('zucchini', 0, 'кабачки для гриля');
        $firstNeed['search_queries'] = ['кабачки', 'овочі для гриля'];
        $secondNeed = $this->need('pepper', 1, 'перець для гриля');
        $secondNeed['search_queries'] = ['перець', 'овочі для гриля'];

        $preparation = CartAgentPreparationData::from(['needs' => [$firstNeed, $secondNeed]], [
            ['name' => 'кабачки'],
            ['name' => 'перець'],
        ]);

        $this->assertSame('овочі для гриля', data_get($preparation->needs, '0.search_queries.1'));
        $this->assertSame('овочі для гриля', data_get($preparation->needs, '1.search_queries.1'));
    }

    public function test_it_deduplicates_an_overlapping_need_emitted_from_another_plan_item(): void
    {
        $preparation = CartAgentPreparationData::from(['needs' => [
            $this->need('tomatoes-primary', 0, 'помідори'),
            $this->need('greens', 1, 'зелень'),
            $this->need('tomatoes-overlap', 1, 'помідори свіжі'),
            $this->need('salad-leaves', 1, 'салатні листки'),
        ]], [
            ['name' => 'помідори'],
            ['name' => 'зелень та салатні листки'],
        ]);

        $this->assertSame(['помідори', 'зелень', 'салатні листки'], array_column($preparation->needs, 'name'));
        $this->assertSame(['n_01', 'n_02', 'n_03'], array_column($preparation->needs, 'key'));
    }

    public function test_it_identifies_broad_multi_sku_needs_without_product_names(): void
    {
        $this->assertTrue(CartAgentPreparationData::requiresMultipleSkuDecomposition([
            'name' => 'Сезонні овочі на гриль',
        ]));
        $this->assertTrue(CartAgentPreparationData::requiresMultipleSkuDecomposition([
            'name' => 'Овочева суміш для салату',
        ]));
        $this->assertTrue(CartAgentPreparationData::requiresMultipleSkuDecomposition([
            'name' => 'зелень та салатні листки',
        ]));
        $this->assertFalse(CartAgentPreparationData::requiresMultipleSkuDecomposition([
            'name' => 'помідори',
        ]));
    }

    public function test_it_rejects_a_decomposed_group_that_omits_other_plan_items(): void
    {
        $this->expectException(UnexpectedValueException::class);

        CartAgentPreparationData::from(['needs' => [
            $this->need('vegetable-a', 1, 'овоч А для гриля'),
            $this->need('vegetable-b', 1, 'овоч Б для гриля'),
        ]], [
            ['name' => 'свинина на шашлик'],
            ['name' => 'овочі для гриля'],
        ]);
    }

    public function test_it_repairs_cross_batch_overlap_from_the_authoritative_plan(): void
    {
        $plan = [
            ['name' => 'овочі для гриля', 'category' => 'food', 'quantity' => 3, 'unit' => 'кг', 'note' => 'Сирі овочі.'],
            ['name' => 'помідори', 'category' => 'food', 'quantity' => 1.5, 'unit' => 'кг', 'note' => 'Окрема позиція.'],
            ['name' => 'зелень та салатні листки', 'category' => 'food', 'quantity' => 3, 'unit' => 'пучки', 'note' => 'Свіжа зелень.'],
        ];
        $payload = ['needs' => [
            $this->need('grill-tomato', 0, 'помідори'),
            $this->need('grill-pepper', 0, 'перець солодкий'),
            $this->need('tomato', 1, 'помідори'),
            $this->need('greens-tomato', 2, 'помідори'),
            $this->need('greens-pepper', 2, 'перець солодкий'),
        ]];

        $preparation = CartAgentPreparationData::from(
            CartAgentPreparationData::repairAgainstPlan($payload, $plan),
            $plan,
        );

        $this->assertCount(2, collect($preparation->needs)->where('source_index', 0));
        $this->assertCount(1, collect($preparation->needs)->where('source_index', 1));
        $this->assertCount(2, collect($preparation->needs)->where('source_index', 2));
        $this->assertSame(
            ['зелень', 'салатні листки'],
            collect($preparation->needs)->where('source_index', 2)->pluck('name')->all(),
        );
    }

    public function test_it_repairs_an_omitted_simple_plan_item_without_inventing_a_new_role(): void
    {
        $plan = [
            ['name' => 'свинина на шашлик', 'category' => 'food', 'quantity' => 2, 'unit' => 'кг', 'note' => 'Без маринаду.'],
            ['name' => 'негазована вода', 'category' => 'water', 'quantity' => 12, 'unit' => 'л', 'note' => 'На всіх.'],
        ];
        $payload = ['needs' => [$this->need('pork', 0, 'свинина на шашлик')]];

        $preparation = CartAgentPreparationData::from(
            CartAgentPreparationData::repairAgainstPlan($payload, $plan),
            $plan,
        );

        $this->assertSame([0, 1], array_column($preparation->needs, 'source_index'));
        $this->assertSame('негазована вода', data_get($preparation->needs, '1.name'));
        $this->assertSame(12.0, data_get($preparation->needs, '1.quantity'));
    }

    public function test_it_normalizes_decomposed_quantities_to_the_authoritative_plan_total(): void
    {
        $preparation = CartAgentPreparationData::from(['needs' => [
            [...$this->need('greens', 0, 'зелень'), 'quantity' => 1, 'unit' => 'пучок'],
            [...$this->need('leaves', 0, 'салатні листки'), 'quantity' => 1, 'unit' => 'пучок'],
        ]], [[
            'name' => 'зелень та салатні листки',
            'category' => 'food',
            'quantity' => 3,
            'unit' => 'пучки',
            'note' => '',
        ]]);

        $this->assertSame(3.0, array_sum(array_column($preparation->needs, 'quantity')));
        $this->assertSame([1.5, 1.5], array_column($preparation->needs, 'quantity'));
        $this->assertSame(['пучки', 'пучки'], array_column($preparation->needs, 'unit'));
    }

    public function test_it_starts_with_the_shortest_query_that_preserves_repeated_product_identity(): void
    {
        $need = [
            ...$this->need('chips', 0, 'Чіпси'),
            'unit' => 'пачка',
            'search_queries' => [
                'чіпси',
                'картопляні чіпси',
                'чипси картопляні',
                'солоні картопляні чіпси',
            ],
        ];

        $preparation = CartAgentPreparationData::from(['needs' => [$need]], [[
            'name' => 'Чіпси',
            'category' => 'food',
            'quantity' => 1,
            'unit' => 'пачка',
            'note' => '',
        ]]);

        $this->assertSame('картопляні чіпси', data_get($preparation->needs, '0.search_query'));
    }

    /** @return array<string, mixed> */
    private function need(string $key, int $sourceIndex, string $name): array
    {
        return [
            'key' => $key,
            'source_index' => $sourceIndex,
            'name' => $name,
            'category' => 'food',
            'quantity' => 1,
            'unit' => 'кг',
            'note' => '',
            'search_queries' => [$name, "{$name} альтернативна назва"],
        ];
    }
}
