<?php

namespace App\Actions;

use App\CartSyncStatus;
use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use App\HarnessEntryKind;
use App\HarnessRunType;
use App\Models\Event;
use App\Models\EventSource;
use App\Services\HarnessRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreEventAnswersAction
{
    public function __construct(
        private readonly StartEventAnalysisAction $startAnalysis,
        private readonly HarnessRecorder $harnessRecorder,
    ) {}

    /**
     * @param  array<int, array{question_key: string, answer: string}>  $answers
     */
    public function execute(Event $event, int $stateVersion, array $answers): int
    {
        $result = DB::transaction(function () use ($event, $stateVersion, $answers): array {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);

            if ($lockedEvent->state_version !== $stateVersion) {
                throw ValidationException::withMessages([
                    'state_version' => 'Гусь уже почув нове. Перегляньте питання ще раз.',
                ]);
            }

            $questions = collect($lockedEvent->state['unresolved_questions'] ?? [])->keyBy('key');
            $batch = (string) Str::ulid();
            $created = 0;
            $recordedAnswers = [];

            foreach ($answers as $position => $answer) {
                $question = $questions->get($answer['question_key']);

                if (! is_array($question)) {
                    throw ValidationException::withMessages([
                        'answers' => 'Одне з питань уже змінилося. Перегляньте їх ще раз.',
                    ]);
                }

                $answerText = Str::squish($answer['answer']);
                $contentHash = hash('sha256', implode('|', [
                    'question_answer',
                    $answer['question_key'],
                    Str::lower($answerText),
                ]));

                if ($lockedEvent->sources()->where('content_hash', $contentHash)->exists()) {
                    continue;
                }

                $source = EventSource::query()->create([
                    'event_id' => $lockedEvent->id,
                    'type' => EventSourceType::Text,
                    'origin' => 'question_answer',
                    'metadata' => [
                        'question_key' => $answer['question_key'],
                        'question' => $question['question'],
                        'answer' => $answerText,
                        'state_version' => $stateVersion,
                    ],
                    'text' => sprintf(
                        'Відповідь організатора на питання «%s»: %s',
                        $question['question'],
                        $answerText,
                    ),
                    'upload_batch' => $batch,
                    'position' => $position,
                    'content_hash' => $contentHash,
                    'status' => EventSourceStatus::Processed,
                    'inclusion' => EventSourceInclusion::Included,
                    'processed_at' => now(),
                ]);
                $created++;
                $recordedAnswers[] = [
                    'source_id' => $source->id,
                    'question_key' => $answer['question_key'],
                    'question' => $question['question'],
                    'answer' => $answerText,
                ];
            }

            if ($created > 0) {
                $lockedEvent->update([
                    'evidence_version' => $lockedEvent->evidence_version + $created,
                    'cart_sync_status' => $lockedEvent->cart_synced_at === null
                        ? $lockedEvent->cart_sync_status
                        : CartSyncStatus::Stale,
                    'last_source_at' => now(),
                ]);
            }

            return ['created' => $created, 'answers' => $recordedAnswers];
        });

        if ($result['created'] > 0) {
            $analysisEvent = $this->startAnalysis->execute($event->fresh());
            $harnessRun = $this->harnessRecorder->start(
                event: $analysisEvent,
                type: HarnessRunType::ContextSynthesis,
                correlationId: $analysisEvent->analysis_task_id,
                metadata: ['evidence_version' => $analysisEvent->evidence_version],
            );

            foreach ($result['answers'] as $answer) {
                $this->harnessRecorder->append(
                    run: $harnessRun,
                    kind: HarnessEntryKind::Answer,
                    title: $answer['question'],
                    message: $answer['answer'],
                    metadata: [
                        'source_id' => $answer['source_id'],
                        'question_key' => $answer['question_key'],
                    ],
                );
            }
        }

        return $result['created'];
    }
}
