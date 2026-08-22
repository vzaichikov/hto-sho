<?php

namespace App\Data;

use App\EventDescriptionReviewReason;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use UnexpectedValueException;

final readonly class EventDescriptionReviewData
{
    public function __construct(
        public bool $accepted,
        public EventDescriptionReviewReason $reason,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function from(array $payload): self
    {
        $validated = Validator::make($payload, [
            'accepted' => ['required', 'boolean'],
            'reason' => ['required', Rule::enum(EventDescriptionReviewReason::class)],
        ])->validate();
        $reason = EventDescriptionReviewReason::from($validated['reason']);

        if ($validated['accepted'] !== ($reason === EventDescriptionReviewReason::Accepted)) {
            throw new UnexpectedValueException('AI description review returned an inconsistent decision.');
        }

        return new self(
            accepted: $validated['accepted'],
            reason: $reason,
        );
    }
}
