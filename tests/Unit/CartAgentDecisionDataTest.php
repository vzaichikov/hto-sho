<?php

namespace Tests\Unit;

use App\Data\CartAgentDecisionData;
use Tests\TestCase;
use UnexpectedValueException;

class CartAgentDecisionDataTest extends TestCase
{
    public function test_missing_inspection_product_is_rejected_for_bounded_model_repair(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Agent selected no catalog product.');

        CartAgentDecisionData::from([
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

    }

    public function test_a_selection_that_does_not_match_the_required_product_becomes_a_skip(): void
    {
        $decision = CartAgentDecisionData::from([
            'action' => 'select',
            'selected_product_id' => 'bundle-id',
            'query' => null,
            'quantity' => 2,
            'reason' => 'Це набір соусу з неповʼязаним напоєм, а не окремий соус.',
            'question' => null,
            'allow_catalog_fallback' => false,
            'candidate_matches_required_product' => false,
            'audit' => [
                'complete' => false,
                'covered_need_keys' => [],
                'remaining_need_keys' => ['n_07'],
                'enough_for_people' => false,
                'warnings' => [],
                'revisit_need_key' => 'n_07',
                'revisit_query' => 'томатний соус',
                'question' => null,
            ],
        ]);

        $this->assertSame('skip', $decision->action);
        $this->assertNull($decision->selectedProductId);
        $this->assertNull($decision->quantity);
        $this->assertFalse($decision->candidateMatchesRequiredProduct);
    }

    public function test_model_safety_evidence_is_preserved_for_staging(): void
    {
        $decision = CartAgentDecisionData::from([
            'action' => 'select',
            'selected_product_id' => 'pork-id',
            'query' => null,
            'quantity' => 2.4,
            'reason' => 'Товар придатний, але склад для алергії не розкрито.',
            'question' => null,
            'safety_evidence' => 'unverified',
            'audit' => [
                'complete' => true,
                'covered_need_keys' => ['n_01'],
                'remaining_need_keys' => [],
                'enough_for_people' => true,
                'warnings' => ['Перевірити паковання.'],
                'revisit_need_key' => null,
                'revisit_query' => null,
                'question' => null,
            ],
        ]);

        $this->assertSame('unverified', $decision->safetyEvidence);
    }

    public function test_model_replacement_signal_is_preserved_for_visible_same_role_staging(): void
    {
        $decision = CartAgentDecisionData::from([
            'action' => 'select',
            'selected_product_id' => 'beef-id',
            'query' => null,
            'quantity' => 1.6,
            'reason' => 'Телятини немає; обрано явну рольову заміну.',
            'question' => null,
            'is_replacement' => true,
            'audit' => [
                'complete' => true,
                'covered_need_keys' => ['n_01'],
                'remaining_need_keys' => [],
                'enough_for_people' => true,
                'warnings' => ['Телятину замінено яловичиною.'],
                'revisit_need_key' => null,
                'revisit_query' => null,
                'question' => null,
            ],
        ]);

        $this->assertTrue($decision->isReplacement);
    }
}
