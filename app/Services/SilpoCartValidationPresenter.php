<?php

namespace App\Services;

use Illuminate\Support\Arr;

final class SilpoCartValidationPresenter
{
    /** @param array<int, array<string, mixed>> $validations @return array<int, array{message: string}> */
    public function present(array $validations): array
    {
        return collect($validations)
            ->filter(fn (mixed $validation): bool => is_array($validation))
            ->reject(function (array $validation): bool {
                $message = (string) Arr::get($validation, 'message');
                $level = mb_strtolower((string) Arr::get($validation, 'level'));
                $type = mb_strtolower((string) Arr::get($validation, 'type'));

                return in_array($message, ['promotion.available', 'order.cost.min'], true)
                    || $level === 'info'
                    || $type === 'info';
            })
            ->map(fn (array $validation): array => [
                'message' => $this->message((string) Arr::get($validation, 'message')),
            ])
            ->unique('message')
            ->values()
            ->all();
    }

    private function message(string $message): string
    {
        return match ($message) {
            'timeslot.not_available' => 'Цей час уже недоступний. Гусь допоможе обрати свіжий.',
            'product.offer.stock.max' => 'Одного з товарів у Сільпо вже менше, ніж просить кошик.',
            'order.payment_types.disabled' => 'Для цього кошика Сільпо ще не відкрило спосіб оплати.',
            default => 'Сільпо просить додатково перевірити кошик.',
        };
    }
}
