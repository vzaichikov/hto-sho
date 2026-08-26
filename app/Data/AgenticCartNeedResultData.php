<?php

namespace App\Data;

final readonly class AgenticCartNeedResultData
{
    /**
     * @param  array<string, mixed>|null  $selectedItem
     * @param  array<int, array{query: string, raw_total_found: int, total_found: int}>  $attempts
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public ?array $selectedItem,
        public array $attempts,
        public array $warnings,
        public ?string $question,
        public CartAgentAuditData $audit,
        public int $toolCallCount,
    ) {}
}
