<?php

namespace App\Contracts;

use App\Data\CartAgentAuditData;
use App\Data\CartAgentDecisionData;
use App\Data\CartAgentPreparationData;
use App\Models\HarnessRun;

interface CartProductAgent
{
    /**
     * @param  array<string, mixed>  $eventContext
     * @param  array<string, mixed>  $shoppingPlan
     */
    public function prepare(
        array $eventContext,
        array $shoppingPlan,
        ?HarnessRun $harnessRun = null,
    ): CartAgentPreparationData;

    /** @param array<string, mixed> $context */
    public function decide(array $context, ?HarnessRun $harnessRun = null): CartAgentDecisionData;

    /** @param array<string, mixed> $context */
    public function audit(array $context, ?HarnessRun $harnessRun = null): CartAgentAuditData;
}
