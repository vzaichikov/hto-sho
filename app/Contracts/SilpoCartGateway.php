<?php

namespace App\Contracts;

use App\Data\SilpoCartContextData;
use App\Data\SilpoCartRefreshCandidateData;
use App\Models\HarnessRun;

interface SilpoCartGateway
{
    public function getReadyCart(string $accessToken, ?HarnessRun $harnessRun = null): ?SilpoCartContextData;

    public function getCartRefreshCandidate(
        string $accessToken,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoCartRefreshCandidateData;

    public function refreshCartTimeslot(
        string $accessToken,
        string $routeFingerprint,
        string $currentSlotFingerprint,
        string $slotStart,
        string $slotEnd,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoCartContextData;

    /** @return array<int, array<string, mixed>> */
    public function searchProducts(
        string $accessToken,
        SilpoCartContextData $cart,
        string $query,
        int $limit = 8,
        ?HarnessRun $harnessRun = null,
    ): array;

    /** @return array{categories: array<int, array<string, mixed>>, sets: array<int, array<string, mixed>>} */
    public function getCatalogScopes(
        string $accessToken,
        SilpoCartContextData $cart,
        ?HarnessRun $harnessRun = null,
    ): array;

    /** @return array<int, array<string, mixed>> */
    public function browseProducts(
        string $accessToken,
        SilpoCartContextData $cart,
        string $scopeType,
        string $scopeSlug,
        int $limit = 12,
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
