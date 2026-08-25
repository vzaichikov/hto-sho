<?php

namespace App\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final readonly class CartAgentSearchIntentData
{
    public function __construct(
        public string $productName,
        public string $purpose,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function from(array $payload): self
    {
        $validated = Validator::make($payload, [
            'product_name' => ['required', 'string', 'max:160'],
            'purpose' => ['present', 'string', 'max:500'],
        ])->validate();

        return new self(
            productName: Str::squish($validated['product_name']),
            purpose: Str::squish($validated['purpose']),
        );
    }
}
