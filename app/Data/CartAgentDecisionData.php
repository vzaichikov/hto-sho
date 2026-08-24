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
        public bool $allowCatalogFallback = false,
        public bool $candidateMatchesRequiredProduct = true,
        public string $safetyEvidence = 'not_required',
        public bool $isReplacement = false,
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
            'allow_catalog_fallback' => ['sometimes', 'boolean'],
            'candidate_matches_required_product' => ['sometimes', 'boolean'],
            'safety_evidence' => ['sometimes', 'in:not_required,verified,unverified'],
            'is_replacement' => ['sometimes', 'boolean'],
        ])->validate();

        $action = $validated['action'];
        $question = $validated['question'] ?? null;
        $candidateMatchesRequiredProduct = (bool) ($validated['candidate_matches_required_product'] ?? true);

        if ($action === 'select' && ! $candidateMatchesRequiredProduct) {
            $action = 'skip';
            $validated['selected_product_id'] = null;
            $validated['quantity'] = null;
        }

        if (in_array($action, ['select', 'inspect'], true)
            && blank($validated['selected_product_id'] ?? null)) {
            throw new UnexpectedValueException('Agent selected no catalog product.');
        }

        if ($action === 'select' && ! is_numeric($validated['quantity'] ?? null)) {
            throw new UnexpectedValueException('Agent selected no product quantity.');
        }

        if ($action === 'retry' && blank($validated['query'] ?? null)) {
            throw new UnexpectedValueException('Agent requested an empty search query.');
        }

        if ($action === 'ask' && blank($question)) {
            throw new UnexpectedValueException('Agent requested an empty human question.');
        }

        return new self(
            action: $action,
            selectedProductId: $validated['selected_product_id'] ?? null,
            query: $validated['query'] ?? null,
            quantity: isset($validated['quantity']) ? (float) $validated['quantity'] : null,
            reason: $validated['reason'],
            question: $question,
            audit: CartAgentAuditData::from($validated['audit']),
            allowCatalogFallback: (bool) ($validated['allow_catalog_fallback'] ?? false),
            candidateMatchesRequiredProduct: $candidateMatchesRequiredProduct,
            safetyEvidence: $validated['safety_evidence'] ?? 'not_required',
            isReplacement: (bool) ($validated['is_replacement'] ?? false),
        );
    }
}
