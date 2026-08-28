<?php

namespace Tests\Feature;

use App\HarnessEntryKind;
use App\HarnessRunStatus;
use App\HarnessRunType;
use App\ImageExtractionStatus;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\HarnessEntry;
use App\Models\HarnessRun;
use App\Models\ImageExtraction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class HarnessJournalTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_read_grouped_journal_and_load_payloads_lazily(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $run = HarnessRun::factory()->for($event)->create([
            'type' => HarnessRunType::ContextSynthesis,
            'status' => HarnessRunStatus::Completed,
            'correlation_id' => 'analysis-210',
        ]);
        $entry = HarnessEntry::factory()->for($run, 'run')->create([
            'sequence' => 1,
            'kind' => HarnessEntryKind::Llm,
            'title' => 'Синтез контексту події',
            'method' => 'POST',
            'endpoint' => 'https://api.openai.test/responses',
            'status_code' => 200,
            'duration_ms' => 123,
            'request_payload' => ['model' => 'test-model'],
            'response_payload' => ['answer' => 'Готово'],
        ]);

        $response = $this->actingAs($owner)->get(route('events.journal.index', $event));

        $response->assertOk()
            ->assertSee('Журнал Гуся')
            ->assertSee('Синтез контексту події')
            ->assertSee('https://api.openai.test/responses')
            ->assertSee('Payload ·')
            ->assertSee('data-harness-entry-payload', false)
            ->assertDontSee('test-model')
            ->assertDontSee('Готово')
            ->assertSee('Зберігання: 90 днів');
        $this->assertSame(1, substr_count($response->getContent(), '<details'));

        $this->actingAs($owner)
            ->get(route('events.journal.entries.payload', [$event, $run, $entry]))
            ->assertOk()
            ->assertJsonPath('request_payload.model', 'test-model')
            ->assertJsonPath('response_payload.answer', 'Готово');
    }

    public function test_journal_is_owner_only_paginated_and_filterable_by_run_type(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        foreach (range(1, 11) as $index) {
            HarnessRun::factory()->for($event)->create([
                'type' => $index === 11 ? HarnessRunType::SilpoCart : HarnessRunType::ContextSynthesis,
                'correlation_id' => $index === 1 ? 'correlation-one-excluded' : 'run-'.$index,
                'created_at' => now()->addSeconds($index),
            ]);
        }

        $this->actingAs($stranger)
            ->get(route('events.journal.index', $event))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('events.journal.index', $event))
            ->assertOk()
            ->assertSee('run-11')
            ->assertDontSee('correlation-one-excluded');

        $this->actingAs($owner)
            ->get(route('events.journal.index', ['event' => $event, 'type' => HarnessRunType::SilpoCart->value]))
            ->assertOk()
            ->assertSee('run-11')
            ->assertDontSee('run-10');
    }

    public function test_image_filter_includes_the_exact_extraction_run_reused_by_the_event(): void
    {
        $owner = User::factory()->create();
        $originalEvent = Event::factory()->for($owner)->create();
        $currentEvent = Event::factory()->for($owner)->create();
        $extraction = ImageExtraction::factory()->for($owner)->create([
            'status' => ImageExtractionStatus::Processed,
        ]);
        EventSource::factory()->for($currentEvent)->create([
            'image_extraction_id' => $extraction->id,
            'used_cached_extraction' => true,
        ]);
        $reusedRun = HarnessRun::factory()->for($originalEvent)->create([
            'type' => HarnessRunType::ImageExtraction,
            'correlation_id' => 'image-'.$extraction->id,
        ]);
        $reusedEntry = HarnessEntry::factory()->for($reusedRun, 'run')->create([
            'title' => 'OCR та класифікація зображення',
            'request_payload' => ['model' => 'ocr-model'],
        ]);
        $unrelatedRun = HarnessRun::factory()->for($originalEvent)->create([
            'type' => HarnessRunType::ImageExtraction,
            'correlation_id' => 'image-unrelated',
        ]);
        $unrelatedEntry = HarnessEntry::factory()->for($unrelatedRun, 'run')->create();

        $this->actingAs($owner)
            ->get(route('events.journal.index', [
                'event' => $currentEvent,
                'type' => HarnessRunType::ImageExtraction->value,
            ]))
            ->assertOk()
            ->assertSee('image-'.$extraction->id)
            ->assertSee('OCR та класифікація зображення')
            ->assertDontSee('image-unrelated');

        $this->actingAs($owner)
            ->get(route('events.journal.entries.payload', [$currentEvent, $reusedRun, $reusedEntry]))
            ->assertOk()
            ->assertJsonPath('request_payload.model', 'ocr-model');

        $this->actingAs($owner)
            ->get(route('events.journal.entries.payload', [$currentEvent, $unrelatedRun, $unrelatedEntry]))
            ->assertNotFound();
    }
}
