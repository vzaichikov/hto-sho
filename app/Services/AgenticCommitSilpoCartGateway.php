<?php

namespace App\Services;

use App\Contracts\AgenticSilpoCartRunner;
use App\Contracts\SilpoCartGateway;
use App\Data\SilpoCartContextData;
use App\Data\SilpoCartRefreshCandidateData;
use App\Data\SilpoFulfilmentSnapshotData;
use App\Models\HarnessRun;
use UnexpectedValueException;

final class AgenticCommitSilpoCartGateway implements SilpoCartGateway
{
    private ?SilpoCartContextData $lastReadyCart = null;

    private ?SilpoCartContextData $modelVerifiedCart = null;

    public function __construct(
        private readonly SilpoCartGateway $delegate,
        private readonly AgenticSilpoCartRunner $runner,
    ) {}

    public function getFulfilmentSnapshot(
        string $accessToken,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoFulfilmentSnapshotData {
        return $this->delegate->getFulfilmentSnapshot($accessToken, $harnessRun);
    }

    public function clearCartProducts(
        string $accessToken,
        string $cartId,
        ?HarnessRun $harnessRun = null,
    ): SilpoFulfilmentSnapshotData {
        return $this->delegate->clearCartProducts($accessToken, $cartId, $harnessRun);
    }

    public function getSavedDeliveryAddresses(
        string $accessToken,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->delegate->getSavedDeliveryAddresses($accessToken, $harnessRun);
    }

    public function findDeliveryAddresses(
        string $accessToken,
        string $query,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->delegate->findDeliveryAddresses($accessToken, $query, $harnessRun);
    }

    public function getAvailableDeliveryTypes(
        string $accessToken,
        float $latitude,
        float $longitude,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->delegate->getAvailableDeliveryTypes(
            $accessToken,
            $latitude,
            $longitude,
            $harnessRun,
        );
    }

    public function getFulfilmentBranches(
        string $accessToken,
        bool $pickup,
        bool $novaPoshta,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->delegate->getFulfilmentBranches($accessToken, $pickup, $novaPoshta, $harnessRun);
    }

    public function findNovaPoshtaSettlements(
        string $accessToken,
        string $query,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->delegate->findNovaPoshtaSettlements($accessToken, $query, $harnessRun);
    }

    public function findNovaPoshtaOffices(
        string $accessToken,
        string $settlementId,
        ?string $query = null,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->delegate->findNovaPoshtaOffices(
            $accessToken,
            $settlementId,
            $query,
            $harnessRun,
        );
    }

    public function getFulfilmentSlots(
        string $accessToken,
        string $branchId,
        string $deliveryType,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->delegate->getFulfilmentSlots($accessToken, $branchId, $deliveryType, $harnessRun);
    }

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
    ): ?SilpoFulfilmentSnapshotData {
        return $this->delegate->updateFulfilment(
            $accessToken,
            $cartId,
            $deliveryType,
            $slotStart,
            $slotEnd,
            $address,
            $shipments,
            $targetBranchId,
            $harnessRun,
        );
    }

    public function getReadyCart(
        string $accessToken,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoCartContextData {
        if ($this->modelVerifiedCart !== null) {
            return $this->modelVerifiedCart;
        }

        $this->lastReadyCart = $this->delegate->getReadyCart($accessToken, $harnessRun);

        return $this->lastReadyCart;
    }

    public function getCartRefreshCandidate(
        string $accessToken,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoCartRefreshCandidateData {
        return $this->delegate->getCartRefreshCandidate($accessToken, $harnessRun);
    }

    public function refreshCartTimeslot(
        string $accessToken,
        string $routeFingerprint,
        string $currentSlotFingerprint,
        string $slotStart,
        string $slotEnd,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoCartContextData {
        return $this->delegate->refreshCartTimeslot(
            $accessToken,
            $routeFingerprint,
            $currentSlotFingerprint,
            $slotStart,
            $slotEnd,
            $harnessRun,
        );
    }

    public function searchProducts(
        string $accessToken,
        SilpoCartContextData $cart,
        string $query,
        int $limit = 30,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->delegate->searchProducts($accessToken, $cart, $query, $limit, $harnessRun);
    }

    public function getCatalogScopes(
        string $accessToken,
        SilpoCartContextData $cart,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->delegate->getCatalogScopes($accessToken, $cart, $harnessRun);
    }

    public function browseProducts(
        string $accessToken,
        SilpoCartContextData $cart,
        string $scopeType,
        string $scopeSlug,
        int $limit = 12,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->delegate->browseProducts(
            $accessToken,
            $cart,
            $scopeType,
            $scopeSlug,
            $limit,
            $harnessRun,
        );
    }

    public function getProductDetails(
        string $accessToken,
        SilpoCartContextData $cart,
        string $slug,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->delegate->getProductDetails($accessToken, $cart, $slug, $harnessRun);
    }

    public function addOrUpdateProducts(
        string $accessToken,
        string $cartId,
        array $products,
        ?HarnessRun $harnessRun = null,
    ): array {
        if ($this->lastReadyCart === null || $this->lastReadyCart->cartId !== $cartId) {
            throw new UnexpectedValueException('Agentic commit has no locked Silpo cart context.');
        }

        $this->modelVerifiedCart = $this->runner->commitApproved(
            $accessToken,
            $this->lastReadyCart,
            $products,
            $harnessRun,
        );

        return ['success' => true];
    }
}
