<?php

namespace App\Data;

use Illuminate\Support\Facades\Validator;

final readonly class EventContextData
{
    /** @param array<string, mixed> $state */
    public function __construct(public array $state) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function from(array $payload): self
    {
        $validated = Validator::make($payload, [
            'summary' => ['required', 'string', 'max:10000'],
            'participants' => ['present', 'array'],
            'participants.*.name' => ['required', 'string', 'max:255'],
            'participants.*.status' => ['required', 'in:confirmed,declined,uncertain,unknown'],
            'participants.*.preferences' => ['present', 'array'],
            'participants.*.preferences.*' => ['string', 'max:1000'],
            'participants.*.restrictions' => ['present', 'array'],
            'participants.*.restrictions.*' => ['string', 'max:1000'],
            'participants.*.allergies' => ['present', 'array'],
            'participants.*.allergies.*' => ['string', 'max:1000'],
            'participants.*.brings' => ['present', 'array'],
            'participants.*.brings.*' => ['string', 'max:1000'],
            'participants.*.source_ids' => ['present', 'array'],
            'participants.*.source_ids.*' => ['integer'],
            'restrictions' => ['present', 'array'],
            'restrictions.*.participant' => ['required', 'string', 'max:255'],
            'restrictions.*.restriction' => ['required', 'string', 'max:1000'],
            'restrictions.*.severity' => ['required', 'in:allergy,hard,preference,unknown'],
            'restrictions.*.source_ids' => ['present', 'array'],
            'restrictions.*.source_ids.*' => ['integer'],
            'agreements' => ['present', 'array'],
            'agreements.*.summary' => ['required', 'string', 'max:2000'],
            'agreements.*.source_ids' => ['present', 'array'],
            'agreements.*.source_ids.*' => ['integer'],
            'warnings' => ['present', 'array'],
            'warnings.*.message' => ['required', 'string', 'max:2000'],
            'warnings.*.source_ids' => ['present', 'array'],
            'warnings.*.source_ids.*' => ['integer'],
            'unresolved_questions' => ['present', 'array'],
            'unresolved_questions.*.question' => ['required', 'string', 'max:2000'],
            'unresolved_questions.*.source_ids' => ['present', 'array'],
            'unresolved_questions.*.source_ids.*' => ['integer'],
            'source_ids' => ['present', 'array', 'min:1'],
            'source_ids.*' => ['integer'],
        ])->validate();

        foreach (['participants', 'restrictions', 'agreements', 'warnings', 'unresolved_questions'] as $section) {
            foreach ($validated[$section] as &$item) {
                $item['source_ids'] = array_values(array_unique($item['source_ids']));
            }
            unset($item);
        }

        $validated['source_ids'] = array_values(array_unique($validated['source_ids']));

        return new self($validated);
    }
}
