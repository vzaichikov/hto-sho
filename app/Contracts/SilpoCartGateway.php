<?php

namespace App\Contracts;

use App\Data\SilpoCartContextData;
use App\Models\HarnessRun;

interface SilpoCartGateway
{
    public function getReadyCart(string $accessToken, ?HarnessRun $harnessRun = null): ?SilpoCartContextData;

    /** @return array<int, array<string, mixed>> */
    public function searchProducts(
        string $accessToken,
        SilpoCartContextData $cart,
        string $query,
        int $limit = 8,
        ?HarnessRun $harnessRun = null,
    ): array;

    /** @return array<string, mixed> */
    public function getProductDetails(
        string $accessToken,
        SilpoCartContextData $cart,
        string $slug,
        ?HarnessRun $harnessRun = null,
    ): array;

    /**
     * @param  array<int, array{productId: string, companyId: string, branchId: string, quantity: float|int, addQuantity: bool}>  $products
     * @return array<string, mixed>
     */
    public function addOrUpdateProducts(
        string $accessToken,
        string $cartId,
        array $products,
        ?HarnessRun $harnessRun = null,
    ): array;
}
