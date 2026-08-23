<?php

namespace App\Data;

use Illuminate\Support\Facades\Validator;

final readonly class CartAgentAuditData
{
    /**
     * @param  array<int, string>  $coveredNeedKeys
     * @param  array<int, string>  $remainingNeedKeys
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public bool $complete,
        public array $coveredNeedKeys,
        public array $remainingNeedKeys,
        public bool $enoughForPeople,
        public array $warnings,
        public ?string $revisitNeedKey,
        public ?string $revisitQuery,
        public ?string $question,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function from(array $payload): self
    {
        $validated = Validator::make($payload, [
            'complete' => ['required', 'boolean'],
            'covered_need_keys' => ['present', 'array'],
            'covered_need_keys.*' => ['string', 'max:80', 'distinct'],
            'remaining_need_keys' => ['present', 'array'],
            'remaining_need_keys.*' => ['string', 'max:80', 'distinct'],
            'enough_for_people' => ['required', 'boolean'],
            'warnings' => ['present', 'array'],
            'warnings.*' => ['string', 'max:1000'],
            'revisit_need_key' => ['nullable', 'string', 'max:80'],
            'revisit_query' => ['nullable', 'string', 'max:160'],
            'question' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        return new self(
            complete: $validated['complete'],
            coveredNeedKeys: $validated['covered_need_keys'],
            remainingNeedKeys: $validated['remaining_need_keys'],
            enoughForPeople: $validated['enough_for_people'],
            warnings: $validated['warnings'],
            revisitNeedKey: $validated['revisit_need_key'] ?? null,
            revisitQuery: $validated['revisit_query'] ?? null,
            question: $validated['question'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'complete' => $this->complete,
            'covered_need_keys' => $this->coveredNeedKeys,
            'remaining_need_keys' => $this->remainingNeedKeys,
            'enough_for_people' => $this->enoughForPeople,
            'warnings' => $this->warnings,
            'revisit_need_key' => $this->revisitNeedKey,
            'revisit_query' => $this->revisitQuery,
            'question' => $this->question,
        ];
    }
}
