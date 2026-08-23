<?php

namespace Tests\Unit;

use App\Services\CartQuantityCalculator;
use PHPUnit\Framework\TestCase;

class CartQuantityCalculatorTest extends TestCase
{
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

    public function test_weight_need_uses_kilograms_and_sale_step(): void
    {
        $quantity = $this->calculator->quantityFor(
            ['quantity' => 2250, 'unit' => 'г'],
            ['weighted' => true, 'step' => 0.1, 'stock' => 10, 'available' => true],
            1,
        );

        $this->assertSame(2.3, $quantity);
    }

    public function test_quantity_never_exceeds_current_stock(): void
    {
        $quantity = $this->calculator->quantityFor(
            ['quantity' => 10, 'unit' => 'шт'],
            ['display_ratio' => '1 шт', 'weighted' => false, 'step' => 1, 'stock' => 3, 'available' => true],
            10,
        );

        $this->assertSame(3.0, $quantity);
    }
}
