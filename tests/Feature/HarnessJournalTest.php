<?php

namespace Tests\Feature;

use App\HarnessEntryKind;
use App\HarnessRunStatus;
use App\HarnessRunType;
use App\Models\Event;
use App\Models\HarnessEntry;
use App\Models\HarnessRun;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class HarnessJournalTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_read_grouped_journal_and_payloads_are_the_only_collapsible_content(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $run = HarnessRun::factory()->for($event)->create([
            'type' => HarnessRunType::ContextSynthesis,
            'status' => HarnessRunStatus::Completed,
            'correlation_id' => 'analysis-210',
        ]);
        HarnessEntry::factory()->for($run, 'run')->create([
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
            ->assertSee('Payload запиту')
            ->assertSee('Payload відповіді')
            ->assertSee('test-model')
            ->assertSee('Готово')
            ->assertSee('Зберігання: 90 днів');
        $this->assertSame(2, substr_count($response->getContent(), '<details'));
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
}
