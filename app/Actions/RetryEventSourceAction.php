<?php

namespace App\Actions;

use App\EventSourceStatus;
use App\EventSourceType;
use App\ImageExtractionStatus;
use App\Jobs\ProcessImageExtractionJob;
use App\Jobs\SummarizeEventContextJob;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\ImageExtraction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetryEventSourceAction
{
    public function execute(Event $event, EventSource $source): void
    {
        $activeTasks = DB::transaction(function () use ($event, $source): array {
            $lockedSource = EventSource::query()
                ->whereBelongsTo($event)
                ->lockForUpdate()
                ->findOrFail($source->id);

            if ($lockedSource->type !== EventSourceType::Image || $lockedSource->status !== EventSourceStatus::Failed) {
                throw ValidationException::withMessages([
                    'source' => 'Повторити можна лише невдалу обробку зображення.',
                ]);
            }

            $extraction = ImageExtraction::query()->lockForUpdate()->find($lockedSource->image_extraction_id);

            if ($extraction === null) {
                throw ValidationException::withMessages(['source' => 'OCR-кеш для цього зображення вже відсутній.']);
            }

            $extraction->update([
                'status' => ImageExtractionStatus::Pending,
                'classification' => null,
                'ocr_text' => null,
                'message_timeline' => null,
                'source_summary' => null,
                'dismissal_reason' => null,
                'processing_error' => null,
                'processing_started_at' => null,
                'processed_at' => null,
            ]);

            $tasks = [];
            $eventIds = [];

            foreach ($extraction->sources()->with('event')->lockForUpdate()->get() as $relatedSource) {
                $relatedSource->update([
                    'status' => EventSourceStatus::Pending,
                    'processing_error' => null,
                    'processed_at' => null,
                ]);
                $eventIds[$relatedSource->event_id] = $relatedSource->event_id;

                if ($relatedSource->event->hasActiveAnalysis()) {
                    $tasks[$relatedSource->event_id] = $relatedSource->event->analysis_task_id;
                }
            }

            Event::query()->whereKey($eventIds)->increment('evidence_version');
            Event::query()->whereKey($eventIds)->update(['last_source_at' => now()]);

            return [$extraction->id, $tasks];
        });

        ProcessImageExtractionJob::dispatch($activeTasks[0])->afterCommit();

        foreach ($activeTasks[1] as $eventId => $taskId) {
            SummarizeEventContextJob::dispatch($eventId, $taskId)
                ->delay(now()->addSeconds(5))
                ->afterCommit();
        }
    }
}
