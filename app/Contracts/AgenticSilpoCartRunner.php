<?php

namespace App\Contracts;

use App\Data\AgenticCartNeedResultData;
use App\Data\CartAgentAuditData;
use App\Data\SilpoCartContextData;
use App\Models\HarnessRun;
use Closure;

interface AgenticSilpoCartRunner
{
    /**
     * @param  array<string, mixed>  $context
     * @param  (Closure(string, ?string): void)|null  $onProgress
     */
    public function selectNeed(
        string $accessToken,
        SilpoCartContextData $cart,
        array $context,
        ?HarnessRun $harnessRun = null,
        ?Closure $onProgress = null,
    ): AgenticCartNeedResultData;

    /** @param array<string, mixed> $context */
    public function audit(array $context, ?HarnessRun $harnessRun = null): CartAgentAuditData;

    /**
     * @param  array<int, array{productId: string, companyId: string, branchId: string, quantity: float|int, addQuantity: bool}>  $products
     */
    public function commitApproved(
        string $accessToken,
        SilpoCartContextData $cart,
        array $products,
        ?HarnessRun $harnessRun = null,
    ): SilpoCartContextData;
}
