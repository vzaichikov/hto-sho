<?php

namespace Tests\Unit;

use App\Data\CartAgentDecisionData;
use Tests\TestCase;

class CartAgentDecisionDataTest extends TestCase
{
    public function test_missing_inspection_product_becomes_a_recoverable_question(): void
    {
        $decision = CartAgentDecisionData::from([
            'action' => 'inspect',
            'selected_product_id' => null,
            'query' => null,
            'quantity' => null,
            'reason' => 'Потрібен сирий шматок замість напівфабрикату.',
            'question' => null,
            'audit' => [
                'complete' => false,
                'covered_need_keys' => [],
                'remaining_need_keys' => ['n_01'],
                'enough_for_people' => false,
                'warnings' => [],
                'revisit_need_key' => 'n_01',
                'revisit_query' => 'свиняча шия',
                'question' => null,
            ],
        ]);

        $this->assertSame('ask', $decision->action);
        $this->assertSame('Потрібен сирий шматок замість напівфабрикату.', $decision->question);
    }
}
