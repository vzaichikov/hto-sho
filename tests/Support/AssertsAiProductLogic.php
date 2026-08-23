<?php

namespace Tests\Support;

use App\Data\ImageExtractionData;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait AssertsAiProductLogic
{
    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $expectations
     */
    private function assertAiState(array $state, array $expectations, string $scenario): void
    {
        if (isset($expectations['participant_count'])) {
            $this->assertCount(
                $expectations['participant_count'],
                $state['participants'] ?? [],
                $this->failure($scenario, 'AI invented or lost participants.'),
            );
        }

        foreach ($expectations['participants'] ?? [] as $expectedParticipant) {
            $participant = collect($state['participants'] ?? [])->first(
                fn (mixed $candidate): bool => is_array($candidate)
                    && Str::lower((string) ($candidate['name'] ?? '')) === Str::lower($expectedParticipant['name']),
            );

            $this->assertIsArray(
                $participant,
                $this->failure($scenario, "Participant [{$expectedParticipant['name']}] is missing."),
            );

            if (isset($expectedParticipant['status'])) {
                $this->assertSame(
                    $expectedParticipant['status'],
                    $participant['status'] ?? null,
                    $this->failure($scenario, "Participant [{$expectedParticipant['name']}] has the wrong current status."),
                );
            }

            foreach (['preferences', 'restrictions', 'allergies', 'brings'] as $field) {
                $this->assertConceptsPresent(
                    $participant[$field] ?? [],
                    $expectedParticipant[$field] ?? [],
                    $scenario,
                    "participant {$expectedParticipant['name']} {$field}",
                );
                $this->assertConceptsAbsent(
                    $participant[$field] ?? [],
                    $expectedParticipant['not_'.$field] ?? [],
                    $scenario,
                    "participant {$expectedParticipant['name']} {$field}",
                );
            }

            if (isset($expectedParticipant['source_ids'])) {
                $this->assertSameIds(
                    $expectedParticipant['source_ids'],
                    $participant['source_ids'] ?? [],
                    $scenario,
                    "participant {$expectedParticipant['name']} provenance",
                );
            }
        }

        foreach ($expectations['absent_participants'] ?? [] as $absentName) {
            $this->assertFalse(
                collect($state['participants'] ?? [])->contains(
                    fn (mixed $candidate): bool => is_array($candidate)
                        && Str::lower((string) ($candidate['name'] ?? '')) === Str::lower($absentName),
                ),
                $this->failure($scenario, "Participant [{$absentName}] must not be invented."),
            );
        }

        foreach ($expectations['restrictions'] ?? [] as $expectedRestriction) {
            $restriction = collect($state['restrictions'] ?? [])->first(function (mixed $candidate) use ($expectedRestriction): bool {
                if (! is_array($candidate)) {
                    return false;
                }

                return Str::lower((string) ($candidate['participant'] ?? '')) === Str::lower($expectedRestriction['participant'])
                    && ($candidate['severity'] ?? null) === $expectedRestriction['severity']
                    && $this->matchesAll((string) ($candidate['restriction'] ?? ''), $expectedRestriction['all'] ?? []);
            });

            $this->assertIsArray(
                $restriction,
                $this->failure($scenario, "Restriction for [{$expectedRestriction['participant']}] is missing or misclassified."),
            );

            if (isset($expectedRestriction['source_ids'])) {
                $this->assertSameIds(
                    $expectedRestriction['source_ids'],
                    $restriction['source_ids'] ?? [],
                    $scenario,
                    "restriction {$expectedRestriction['participant']} provenance",
                );
            }
        }

        foreach ($expectations['not_restrictions'] ?? [] as $forbiddenRestriction) {
            $this->assertFalse(
                collect($state['restrictions'] ?? [])->contains(function (mixed $candidate) use ($forbiddenRestriction): bool {
                    if (! is_array($candidate)) {
                        return false;
                    }

                    return Str::lower((string) ($candidate['participant'] ?? '')) === Str::lower($forbiddenRestriction['participant'])
                        && $this->matchesAll((string) ($candidate['restriction'] ?? ''), $forbiddenRestriction['all'] ?? []);
                }),
                $this->failure($scenario, "Restriction was wrongly attributed to [{$forbiddenRestriction['participant']}]."),
            );
        }

        $this->assertStructuredTexts(
            $state['agreements'] ?? [],
            'summary',
            $expectations['agreements'] ?? [],
            $expectations['not_agreements'] ?? [],
            $scenario,
            'agreement',
        );
        $this->assertStructuredTexts(
            $state['warnings'] ?? [],
            'message',
            $expectations['warnings'] ?? [],
            [],
            $scenario,
            'warning',
        );
        $this->assertStructuredTexts(
            $state['unresolved_questions'] ?? [],
            'question',
            $expectations['unresolved'] ?? [],
            $expectations['not_unresolved'] ?? [],
            $scenario,
            'unresolved question',
            ['impact'],
        );

        $this->assertCountBounds($state['warnings'] ?? [], $expectations, 'warnings', $scenario);
        $this->assertCountBounds($state['unresolved_questions'] ?? [], $expectations, 'unresolved', $scenario);

        if (isset($expectations['warnings_or_unresolved_min'])) {
            $this->assertGreaterThanOrEqual(
                $expectations['warnings_or_unresolved_min'],
                count($state['warnings'] ?? []) + count($state['unresolved_questions'] ?? []),
                $this->failure($scenario, 'A real contradiction was resolved arbitrarily instead of being surfaced.'),
            );
        }

        if (isset($expectations['source_ids'])) {
            $this->assertSameIds(
                $expectations['source_ids'],
                $state['source_ids'] ?? [],
                $scenario,
                'state provenance',
            );
        }
    }

    /** @param array<string, mixed> $expectations */
    private function assertAiImage(ImageExtractionData $image, array $expectations, string $scenario): void
    {
        $this->assertSame(
            $expectations['classification'],
            $image->classification->value,
            $this->failure($scenario, 'Image classification changed.'),
        );

        if (isset($expectations['message_count'])) {
            $this->assertCount(
                $expectations['message_count'],
                $image->messageTimeline,
                $this->failure($scenario, 'Image produced an unexpected message timeline.'),
            );
        }

        if (($expectations['dismissal_reason_required'] ?? false) === true) {
            $this->assertNotEmpty(
                $image->dismissalReason,
                $this->failure($scenario, 'Irrelevant image has no dismissal reason.'),
            );
        }

        if (($expectations['dismissal_reason_required'] ?? null) === false) {
            $this->assertNull(
                $image->dismissalReason,
                $this->failure($scenario, 'Relevant image must not carry an irrelevant dismissal reason.'),
            );
        }
    }

    /**
     * @param  array<int, string>  $values
     * @param  array<int, string>  $concepts
     */
    private function assertConceptsPresent(
        array $values,
        array $concepts,
        string $scenario,
        string $field,
    ): void {
        foreach ($concepts as $concept) {
            $this->assertTrue(
                collect($values)->contains(fn (mixed $value): bool => is_string($value) && $this->matchesPattern($value, $concept)),
                $this->failure($scenario, "Expected concept [{$concept}] is missing from {$field}."),
            );
        }
    }

    /**
     * @param  array<int, string>  $values
     * @param  array<int, string>  $concepts
     */
    private function assertConceptsAbsent(
        array $values,
        array $concepts,
        string $scenario,
        string $field,
    ): void {
        foreach ($concepts as $concept) {
            $this->assertFalse(
                collect($values)->contains(fn (mixed $value): bool => is_string($value) && $this->matchesPattern($value, $concept)),
                $this->failure($scenario, "Superseded or foreign concept [{$concept}] leaked into {$field}."),
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array{all: array<int, string>}>  $expected
     * @param  array<int, array{all: array<int, string>}>  $forbidden
     * @param  array<int, string>  $additionalFields
     */
    private function assertStructuredTexts(
        array $items,
        string $textField,
        array $expected,
        array $forbidden,
        string $scenario,
        string $label,
        array $additionalFields = [],
    ): void {
        foreach ($expected as $expectation) {
            $match = collect($items)->first(function (mixed $item) use ($expectation, $textField, $additionalFields): bool {
                if (! is_array($item)) {
                    return false;
                }

                $text = collect([$textField, ...$additionalFields])
                    ->map(fn (string $field): string => (string) ($item[$field] ?? ''))
                    ->implode(' ');

                return $this->matchesAll($text, $expectation['all']);
            });

            $this->assertIsArray(
                $match,
                $this->failure($scenario, "Expected {$label} [".implode(', ', $expectation['all']).'] is missing.'),
            );

            if (isset($expectation['source_ids'])) {
                $this->assertSameIds(
                    $expectation['source_ids'],
                    $match['source_ids'] ?? [],
                    $scenario,
                    "{$label} provenance",
                );
            }
        }

        foreach ($forbidden as $expectation) {
            $this->assertFalse(
                collect($items)->contains(fn (mixed $item): bool => is_array($item)
                    && $this->matchesAll((string) ($item[$textField] ?? ''), $expectation['all'])),
                $this->failure($scenario, "Forbidden {$label} [".implode(', ', $expectation['all']).'] is present.'),
            );
        }
    }

    /** @param array<string, mixed> $expectations */
    private function assertCountBounds(array $items, array $expectations, string $field, string $scenario): void
    {
        if (isset($expectations[$field.'_min'])) {
            $this->assertGreaterThanOrEqual(
                $expectations[$field.'_min'],
                count($items),
                $this->failure($scenario, "Too few {$field}."),
            );
        }

        if (isset($expectations[$field.'_max'])) {
            $this->assertLessThanOrEqual(
                $expectations[$field.'_max'],
                count($items),
                $this->failure($scenario, "Too many {$field}."),
            );
        }
    }

    /** @param array<int, string> $patterns */
    private function matchesAll(string $value, array $patterns): bool
    {
        return collect($patterns)->every(fn (string $pattern): bool => $this->matchesPattern($value, $pattern));
    }

    private function matchesPattern(string $value, string $pattern): bool
    {
        return preg_match('~'.$pattern.'~iu', $value) === 1;
    }

    /** @param array<int, int> $expected */
    private function assertSameIds(array $expected, array $actual, string $scenario, string $field): void
    {
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, $this->failure($scenario, "Wrong {$field}."));
    }

    private function failure(string $scenario, string $reason): string
    {
        return "AI regression [{$scenario}] failed: {$reason}";
    }

    /** @param array<string, mixed> $array */
    private function containsRecursiveKey(array $array, string $key): bool
    {
        if (Arr::has($array, $key)) {
            return true;
        }

        foreach ($array as $value) {
            if (is_array($value) && $this->containsRecursiveKey($value, $key)) {
                return true;
            }
        }

        return false;
    }
}
