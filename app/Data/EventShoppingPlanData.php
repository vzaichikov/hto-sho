<?php

namespace App\Data;

use Illuminate\Support\Facades\Validator;

final readonly class EventShoppingPlanData
{
    /** @param array<string, mixed> $plan */
    public function __construct(public array $plan) {}

    /** @param array<string, mixed> $payload */
    public static function from(array $payload): self
    {
        $validated = Validator::make($payload, [
            'summary' => ['required', 'string', 'max:5000'],
            'serves' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'items' => ['present', 'array'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.category' => ['required', 'in:food,water,soft_drinks,alcohol,supplies,other'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'items.*.unit' => ['required', 'string', 'max:100'],
            'items.*.note' => ['present', 'string', 'max:1000'],
            'warnings' => ['present', 'array'],
            'warnings.*' => ['string', 'max:2000'],
            'unanswered_question_keys' => ['present', 'array'],
            'unanswered_question_keys.*' => ['string', 'max:80'],
        ])->validate();

        $validated['unanswered_question_keys'] = array_values(array_unique(
            $validated['unanswered_question_keys'],
        ));

        return new self($validated);
    }
}
