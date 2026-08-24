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

    public function test_it_copies_optional_status_from_the_authoritative_plan(): void
    {
        $preparation = CartAgentPreparationData::from([
            'needs' => [
                $this->need('water', 0, 'вода'),
                $this->need('bags', 1, 'пакети для сміття'),
            ],
        ], [
            ['name' => 'вода', 'optional' => false],
            ['name' => 'пакети для сміття', 'optional' => true],
        ]);

        $this->assertSame([false, true], array_column($preparation->needs, 'optional'));
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
            'minimum_distinct_products' => 2,
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
            'minimum_distinct_products' => 2,
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

    public function test_it_rejects_an_exact_duplicate_product_name_across_plan_items(): void
    {
        $this->expectException(UnexpectedValueException::class);

        CartAgentPreparationData::from(['needs' => [
            $this->need('tomatoes-primary', 0, 'помідори'),
            $this->need('greens', 1, 'зелень'),
            $this->need('tomatoes-overlap', 1, '  ПОМІДОРИ '),
            $this->need('salad-leaves', 1, 'салатні листки'),
        ]], [
            ['name' => 'помідори'],
            ['name' => 'зелень та салатні листки', 'minimum_distinct_products' => 2],
        ]);
    }

    public function test_it_preserves_distinct_products_that_share_a_generic_first_word(): void
    {
        $preparation = CartAgentPreparationData::from(['needs' => [
            $this->need('plates', 0, 'одноразові тарілки'),
            $this->need('cups', 1, 'одноразові стакани'),
        ]], [
            ['name' => 'одноразові тарілки'],
            ['name' => 'одноразові стакани'],
        ]);

        $this->assertSame(
            ['одноразові тарілки', 'одноразові стакани'],
            array_column($preparation->needs, 'name'),
        );
    }

    public function test_it_uses_the_language_agnostic_minimum_distinct_products_contract(): void
    {
        $preparation = CartAgentPreparationData::from(['needs' => [
            $this->need('component-a', 0, 'Component A'),
            $this->need('component-b', 0, 'Component B'),
        ]], [[
            'name' => 'Composite need',
            'minimum_distinct_products' => 2,
        ]]);

        $this->assertCount(2, $preparation->needs);
    }

    public function test_it_rejects_a_decomposed_group_that_omits_other_plan_items(): void
    {
        $this->expectException(UnexpectedValueException::class);

        CartAgentPreparationData::from(['needs' => [
            $this->need('vegetable-a', 1, 'овоч А для гриля'),
            $this->need('vegetable-b', 1, 'овоч Б для гриля'),
        ]], [
            ['name' => 'свинина на шашлик'],
            ['name' => 'овочі для гриля', 'minimum_distinct_products' => 2],
        ]);
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

    public function test_it_starts_with_the_models_best_positive_catalog_query(): void
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

        $this->assertSame('чіпси', data_get($preparation->needs, '0.search_query'));
    }

    public function test_it_does_not_replace_a_named_product_query_with_a_broader_family_query(): void
    {
        $need = [
            ...$this->need('sparkling', 0, 'Named sparkling wine family'),
            'category' => 'alcohol',
            'unit' => 'bottle',
            'search_queries' => [
                'Named Family',
                'sparkling wine',
            ],
        ];

        $preparation = CartAgentPreparationData::from(['needs' => [$need]], [[
            'name' => 'Named sparkling wine family',
            'category' => 'alcohol',
            'quantity' => 1,
            'unit' => 'bottle',
            'note' => '',
        ]]);

        $this->assertSame('Named Family', data_get($preparation->needs, '0.search_query'));
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
