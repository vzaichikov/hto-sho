<?php

namespace App\Data;

use Illuminate\Support\Facades\Validator;
use UnexpectedValueException;

final readonly class CartAgentDecisionData
{
    public function __construct(
        public string $action,
        public ?string $selectedProductId,
        public ?string $query,
        public ?float $quantity,
        public string $reason,
        public ?string $question,
        public CartAgentAuditData $audit,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function from(array $payload): self
    {
        $validated = Validator::make($payload, [
            'action' => ['required', 'in:select,retry,inspect,skip,ask'],
            'selected_product_id' => ['nullable', 'string', 'max:100'],
            'query' => ['nullable', 'string', 'max:160'],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'reason' => ['required', 'string', 'max:1000'],
            'question' => ['nullable', 'string', 'max:1000'],
            'audit' => ['required', 'array'],
        ])->validate();

        if (in_array($validated['action'], ['select', 'inspect'], true)
            && blank($validated['selected_product_id'] ?? null)) {
            throw new UnexpectedValueException('Agent selected no catalog product.');
        }

        if ($validated['action'] === 'select' && ! is_numeric($validated['quantity'] ?? null)) {
            throw new UnexpectedValueException('Agent selected no product quantity.');
        }

        if ($validated['action'] === 'retry' && blank($validated['query'] ?? null)) {
            throw new UnexpectedValueException('Agent requested an empty search query.');
        }

        if ($validated['action'] === 'ask' && blank($validated['question'] ?? null)) {
            throw new UnexpectedValueException('Agent requested an empty human question.');
        }

        return new self(
            action: $validated['action'],
            selectedProductId: $validated['selected_product_id'] ?? null,
            query: $validated['query'] ?? null,
            quantity: isset($validated['quantity']) ? (float) $validated['quantity'] : null,
            reason: $validated['reason'],
            question: $validated['question'] ?? null,
            audit: CartAgentAuditData::from($validated['audit']),
        );
    }
}
