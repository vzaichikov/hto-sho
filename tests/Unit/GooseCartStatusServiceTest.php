<?php

namespace Tests\Unit;

use App\Services\GooseCartStatusService;
use Tests\TestCase;

class GooseCartStatusServiceTest extends TestCase
{
    public function test_phrase_library_has_at_least_one_hundred_operational_statuses(): void
    {
        $phrases = config('goose_cart_phrases');

        $this->assertGreaterThanOrEqual(100, collect($phrases)->sum(fn (array $group): int => count($group)));
        $this->assertArrayHasKey('searching', $phrases);
        $this->assertArrayHasKey('auditing', $phrases);
        $this->assertArrayHasKey('blocked', $phrases);
        $this->assertContains('нє, це шото дуже дорого', $phrases['expensive']);
    }

    public function test_product_placeholder_is_replaced_and_an_immediate_repeat_is_avoided(): void
    {
        $service = new GooseCartStatusService;
        $first = $service->phrase('searching', 9, 2, 'помідори');
        $second = $service->phrase('searching', 9, 2, 'помідори', $first);

        $this->assertStringContainsString('помідори', $first);
        $this->assertStringNotContainsString('%product%', $first);
        $this->assertNotSame($first, $second);
    }

    public function test_real_search_query_uses_the_query_aware_phrase_library(): void
    {
        $service = new GooseCartStatusService;
        $message = $service->phrase(
            'searching',
            9,
            2,
            'Виделки та ножі одноразові',
            query: 'одноразові столові прибори',
        );

        $this->assertStringContainsString('одноразові столові прибори', $message);
        $this->assertStringNotContainsString('%query%', $message);
    }
}
