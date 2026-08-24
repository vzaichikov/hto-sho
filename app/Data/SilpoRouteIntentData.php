<?php

namespace App\Data;

use DateTimeImmutable;
use RuntimeException;

final readonly class SilpoRouteIntentData
{
    /** @var array<int, string> */
    public const DELIVERY_PREFERENCES = [
        'home',
        'wide_assortment',
        'self_pickup',
        'nova_poshta',
        'unspecified',
    ];

    public function __construct(
        public string $action,
        public ?string $addressQuery,
        public ?string $city,
        public ?string $street,
        public ?string $house,
        public string $deliveryPreference,
        public ?string $novaPoshtaCity,
        public ?string $novaPoshtaOfficeHint,
        public ?string $requestedLocalDate,
        public ?string $requestedTimeFrom,
        public ?string $requestedTimeTo,
        public bool $needsClarification,
        public ?string $clarificationQuestion,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function from(array $payload): self
    {
        self::assertRequiredKeys($payload);

        $action = self::requiredEnum($payload['action'], ['keep_current', 'change'], 'action');
        $deliveryPreference = self::requiredEnum(
            $payload['delivery_preference'],
            self::DELIVERY_PREFERENCES,
            'delivery_preference',
        );
        $addressQuery = self::nullableString($payload['address_query'], 'address_query');
        $city = self::nullableString($payload['city'], 'city');
        $street = self::nullableString($payload['street'], 'street');
        $house = self::nullableString($payload['house'], 'house');
        $novaPoshtaCity = self::nullableString($payload['nova_poshta_city'], 'nova_poshta_city');
        $novaPoshtaOfficeHint = self::nullableString(
            $payload['nova_poshta_office_hint'],
            'nova_poshta_office_hint',
        );
        $requestedLocalDate = self::nullableString($payload['requested_local_date'], 'requested_local_date');
        $requestedTimeFrom = self::nullableString($payload['requested_time_from'], 'requested_time_from');
        $requestedTimeTo = self::nullableString($payload['requested_time_to'], 'requested_time_to');

        if (! is_bool($payload['needs_clarification'])) {
            throw new RuntimeException('AI route intent field [needs_clarification] must be boolean.');
        }

        $clarificationQuestion = self::nullableString(
            $payload['clarification_question'],
            'clarification_question',
        );

        if ($requestedLocalDate !== null && ! self::isDate($requestedLocalDate)) {
            throw new RuntimeException('AI route intent field [requested_local_date] must be a local ISO date.');
        }

        foreach ([$requestedTimeFrom, $requestedTimeTo] as $time) {
            if ($time !== null && preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $time) !== 1) {
                throw new RuntimeException('AI route intent time fields must use HH:MM.');
            }
        }

        if ($requestedTimeFrom !== null && $requestedTimeTo !== null && $requestedTimeFrom >= $requestedTimeTo) {
            throw new RuntimeException('AI route intent time range must end after it starts.');
        }

        if ($addressQuery === null && $city !== null) {
            $addressQuery = collect([$city, $street, $house])->filter()->implode(', ');
        }

        [$needsClarification, $clarificationQuestion] = self::clarification(
            action: $action,
            deliveryPreference: $deliveryPreference,
            addressQuery: $addressQuery,
            city: $city,
            street: $street,
            house: $house,
            novaPoshtaCity: $novaPoshtaCity,
            requestedLocalDate: $requestedLocalDate,
            requestedTimeFrom: $requestedTimeFrom,
            requestedTimeTo: $requestedTimeTo,
            modelNeedsClarification: $payload['needs_clarification'],
            modelQuestion: $clarificationQuestion,
        );

        return new self(
            action: $action,
            addressQuery: $addressQuery,
            city: $city,
            street: $street,
            house: $house,
            deliveryPreference: $deliveryPreference,
            novaPoshtaCity: $novaPoshtaCity,
            novaPoshtaOfficeHint: $novaPoshtaOfficeHint,
            requestedLocalDate: $requestedLocalDate,
            requestedTimeFrom: $requestedTimeFrom,
            requestedTimeTo: $requestedTimeTo,
            needsClarification: $needsClarification,
            clarificationQuestion: $clarificationQuestion,
        );
    }

    /** @return array<string, string|null> */
    public function timePreference(): array
    {
        return [
            'date' => $this->requestedLocalDate,
            'from' => $this->requestedTimeFrom,
            'to' => $this->requestedTimeTo,
        ];
    }

    public function novaPoshtaQuery(): ?string
    {
        return $this->novaPoshtaCity ?? $this->city;
    }

    /** @param array<string, mixed> $payload */
    private static function assertRequiredKeys(array $payload): void
    {
        $required = [
            'action',
            'address_query',
            'city',
            'street',
            'house',
            'delivery_preference',
            'nova_poshta_city',
            'nova_poshta_office_hint',
            'requested_local_date',
            'requested_time_from',
            'requested_time_to',
            'needs_clarification',
            'clarification_question',
        ];

        if (collect($required)->contains(fn (string $key): bool => ! array_key_exists($key, $payload))) {
            throw new RuntimeException('AI route intent is missing required fields.');
        }
    }

    /** @param array<int, string> $allowed */
    private static function requiredEnum(mixed $value, array $allowed, string $field): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new RuntimeException("AI route intent field [{$field}] is invalid.");
        }

        return $value;
    }

    private static function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException("AI route intent field [{$field}] must be a string or null.");
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** @return array{bool, string|null} */
    private static function clarification(
        string $action,
        string $deliveryPreference,
        ?string $addressQuery,
        ?string $city,
        ?string $street,
        ?string $house,
        ?string $novaPoshtaCity,
        ?string $requestedLocalDate,
        ?string $requestedTimeFrom,
        ?string $requestedTimeTo,
        bool $modelNeedsClarification,
        ?string $modelQuestion,
    ): array {
        if ($action === 'keep_current') {
            return $modelNeedsClarification
                ? [true, $modelQuestion ?? 'Лишаємо нинішній маршрут кошика без змін?']
                : [false, null];
        }

        if ($deliveryPreference === 'nova_poshta' && blank($novaPoshtaCity ?? $city)) {
            return [true, 'У якому місті шукати відділення або поштомат Нової пошти?'];
        }

        if ($deliveryPreference !== 'nova_poshta') {
            if (blank($city)) {
                return [true, 'У якому місті Гусю шукати маршрут?'];
            }

            if (blank($street)) {
                return [true, 'Яку вулицю має знайти Гусь?'];
            }

            if (blank($house)) {
                return [true, 'Який номер будинку має знайти Гусь?'];
            }

            if (blank($addressQuery)) {
                return [true, 'Напишіть місто, вулицю й номер будинку одним реченням.'];
            }
        }

        if (($requestedTimeFrom !== null || $requestedTimeTo !== null) && $requestedLocalDate === null) {
            return [true, 'На який день Гусю шукати цей час?'];
        }

        if ($modelNeedsClarification) {
            return [true, $modelQuestion ?? 'Уточніть, будь ласка, адресу або спосіб отримання.'];
        }

        return [false, null];
    }
}
