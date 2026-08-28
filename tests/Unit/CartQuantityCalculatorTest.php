<?php

namespace Tests\Unit;

use App\Services\CartQuantityCalculator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class CartQuantityCalculatorTest extends TestCase
{
    public function test_count_like_bunches_round_up_even_when_the_package_ratio_is_weight_based(): void
    {
        $quantity = (new CartQuantityCalculator)->quantityFor([
            'quantity' => 1.5,
            'unit' => 'пучки',
        ], [
            'display_ratio' => '50г',
            'weighted' => false,
            'step' => 1,
            'stock' => 10,
            'available' => true,
        ], 1);

        $this->assertSame(2.0, $quantity);
    }

    private CartQuantityCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new CartQuantityCalculator;
    }

    public function test_search_query_drops_packaging_but_keeps_the_product_description(): void
    {
        $query = $this->calculator->normalizeSearchQuery('Молоко безлактозне 2,5% 6 пляшок 1,5 л');

        $this->assertSame('Молоко безлактозне 2,5%', $query);
    }

    public function test_volume_need_is_converted_to_whole_packages(): void
    {
        $quantity = $this->calculator->quantityFor(
            ['quantity' => 8, 'unit' => 'л'],
            ['display_ratio' => '1,5 л', 'weighted' => false, 'step' => 1, 'stock' => 20, 'available' => true],
            1,
        );

        $this->assertSame(6.0, $quantity);
    }

    public function test_requested_package_count_is_not_divided_by_the_items_inside_each_package(): void
    {
        $quantity = $this->calculator->quantityFor(
            ['quantity' => 2, 'unit' => 'пачки'],
            ['display_ratio' => '50шт/уп', 'weighted' => false, 'step' => 1, 'stock' => 20, 'available' => true],
            2,
        );

        $this->assertSame(2.0, $quantity);
    }

    public function test_non_exact_package_volume_has_an_explicit_rounding_note(): void
    {
        $note = $this->calculator->packageRoundingNote(
            ['quantity' => 4, 'unit' => 'л'],
            ['display_ratio' => '1,25 л', 'weighted' => false],
            4,
        );

        $this->assertSame('⚠️ Пакування 1,25 л: 4 шт. дають 5 л замість 4 л.', $note);
        $this->assertNull($this->calculator->packageRoundingNote(
            ['quantity' => 12, 'unit' => 'л'],
            ['display_ratio' => '1,5 л', 'weighted' => false],
            8,
        ));
    }

    public function test_package_overage_prefers_an_exact_volume_combination(): void
    {
        $need = ['quantity' => 12, 'unit' => 'л'];

        $this->assertSame(0.0, $this->calculator->packageOverageInBaseUnits(
            $need,
            ['display_ratio' => '1,5 л', 'weighted' => false],
        ));
        $this->assertSame(3000.0, $this->calculator->packageOverageInBaseUnits(
            $need,
            ['display_ratio' => '5 л', 'weighted' => false],
        ));
    }

    public function test_weight_need_uses_kilograms_and_sale_step(): void
    {
        $quantity = $this->calculator->quantityFor(
            ['quantity' => 2250, 'unit' => 'г'],
            ['weighted' => true, 'step' => 0.1, 'stock' => 10, 'available' => true],
            1,
        );

        $this->assertSame(2.3, $quantity);
    }

    public function test_insufficient_stock_rejects_the_candidate_instead_of_underfilling_the_need(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->calculator->quantityFor(
            ['quantity' => 10, 'unit' => 'шт'],
            ['display_ratio' => '1 шт', 'weighted' => false, 'step' => 1, 'stock' => 3, 'available' => true],
            10,
        );
    }

    public function test_partial_stock_mode_caps_to_the_purchasable_remainder_with_a_warning(): void
    {
        $need = ['quantity' => 3, 'unit' => 'пучки'];
        $product = [
            'display_ratio' => '50 г',
            'weighted' => false,
            'step' => 1,
            'stock' => 1,
            'available' => true,
        ];

        $quantity = $this->calculator->quantityFor($need, $product, 3, true);

        $this->assertSame(1.0, $quantity);
        $this->assertSame(3.0, $this->calculator->requiredQuantityFor($need, $product, 3));
        $this->assertSame(
            '⚠️ Залишок у Сільпо не покриває всю потребу «3 пучки»: Гусь додав доступний максимум 1 шт. після вичерпання повних альтернатив.',
            $this->calculator->partialStockNote($need, $product, 3, $quantity),
        );
    }
}
