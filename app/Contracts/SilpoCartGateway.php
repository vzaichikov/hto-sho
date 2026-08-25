<?php

namespace App\Contracts;

use App\Data\SilpoCartContextData;
use App\Data\SilpoCartRefreshCandidateData;
use App\Data\SilpoFulfilmentSnapshotData;
use App\Models\HarnessRun;

interface SilpoCartGateway
{
    public function getFulfilmentSnapshot(
        string $accessToken,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoFulfilmentSnapshotData;

    public function clearCartProducts(
        string $accessToken,
        string $cartId,
        ?HarnessRun $harnessRun = null,
    ): SilpoFulfilmentSnapshotData;

    /** @return array<int, array<string, mixed>> */
    public function getSavedDeliveryAddresses(
        string $accessToken,
        ?HarnessRun $harnessRun = null,
    ): array;

    /** @return array<int, array<string, mixed>> */
    public function findDeliveryAddresses(
        string $accessToken,
        string $query,
        ?HarnessRun $harnessRun = null,
    ): array;

    /** @return array<int, array<string, mixed>> */
    public function getAvailableDeliveryTypes(
        string $accessToken,
        float $latitude,
        float $longitude,
        ?HarnessRun $harnessRun = null,
    ): array;

    /** @return array<int, array<string, mixed>> */
    public function getFulfilmentBranches(
        string $accessToken,
        bool $pickup,
        bool $novaPoshta,
        ?HarnessRun $harnessRun = null,
    ): array;

    /** @return array<int, array<string, mixed>> */
    public function findNovaPoshtaSettlements(
        string $accessToken,
        string $query,
        ?HarnessRun $harnessRun = null,
    ): array;

    /** @return array<int, array<string, mixed>> */
    public function findNovaPoshtaOffices(
        string $accessToken,
        string $settlementId,
        ?string $query = null,
        ?HarnessRun $harnessRun = null,
    ): array;

    /** @return array<int, array<string, mixed>> */
    public function getFulfilmentSlots(
        string $accessToken,
        string $branchId,
        string $deliveryType,
        ?HarnessRun $harnessRun = null,
    ): array;

    /**
     * @param  array<string, mixed>  $address
     * @param  array<int, array{companyId: string, branchId: string}>  $shipments
     */
    public function updateFulfilment(
        string $accessToken,
        string $cartId,
        string $deliveryType,
        string $slotStart,
        string $slotEnd,
        array $address,
        array $shipments,
        ?string $targetBranchId = null,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoFulfilmentSnapshotData;

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
        int $limit = 30,
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
