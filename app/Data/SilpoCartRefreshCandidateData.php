<?php

namespace App\Data;

final readonly class SilpoCartRefreshCandidateData
{
    public function __construct(
        public string $deliveryType,
        public string $currentSlotStart,
        public string $currentSlotEnd,
        public string $candidateSlotStart,
        public string $candidateSlotEnd,
        public string $routeFingerprint,
        public string $currentSlotFingerprint,
    ) {}

    public function deliveryLabel(): string
    {
        return match ($this->deliveryType) {
            'DeliveryHome' => 'Доставка Сільпо',
            'WideAssortDelivery' => 'Доставка широкого асортименту',
            'NovaPoshta' => 'Нова пошта',
            'SelfPickup' => 'Самовивіз',
            default => 'Обраний спосіб Сільпо',
        };
    }
}
