<?php

namespace Tests\Unit;

use App\EventAnalysisStage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class EventAnalysisStageTest extends TestCase
{
    public function test_analysis_messages_rotate_through_an_eighty_phrase_goose_pool(): void
    {
        $phraseGroups = config('goose_analysis_phrases');

        $this->assertIsArray($phraseGroups);
        $this->assertSame(80, collect($phraseGroups)->sum(fn (array $phrases): int => count($phrases)));

        foreach (EventAnalysisStage::cases() as $stage) {
            $this->assertArrayHasKey($stage->value, $phraseGroups);
            $this->assertNotEmpty($phraseGroups[$stage->value]);
        }

        $startedAt = CarbonImmutable::parse('2026-08-25 12:00:00', 'Europe/Kiev');
        Date::setTestNow($startedAt);

        try {
            $firstMessage = EventAnalysisStage::Summarizing->message('analysis-task', $startedAt);
            $this->assertContains($firstMessage, $phraseGroups['summarizing']);
            $this->assertSame(
                $firstMessage,
                EventAnalysisStage::Summarizing->message('analysis-task', $startedAt),
            );

            Date::setTestNow($startedAt->addSeconds(4));
            $nextMessage = EventAnalysisStage::Summarizing->message('analysis-task', $startedAt);
            $this->assertContains($nextMessage, $phraseGroups['summarizing']);
            $this->assertNotSame($firstMessage, $nextMessage);
        } finally {
            Date::setTestNow();
        }
    }
}
