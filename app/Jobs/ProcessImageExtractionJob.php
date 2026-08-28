<?php

namespace App\Jobs;

use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\HarnessEntryKind;
use App\HarnessRunStatus;
use App\HarnessRunType;
use App\ImageClassification;
use App\ImageExtractionStatus;
use App\Models\HarnessRun;
use App\Models\ImageExtraction;
use App\Services\ContextAnalysisService;
use App\Services\HarnessRecorder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProcessImageExtractionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 170;

    public int $tries = 3;

    public int $uniqueFor = 900;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly int $imageExtractionId)
    {
        $this->onQueue('ai-sources');
    }

    public function uniqueId(): string
    {
        return (string) $this->imageExtractionId;
    }

    public function handle(ContextAnalysisService $analysis, HarnessRecorder $harnessRecorder): void
    {
        $extraction = DB::transaction(function (): ?ImageExtraction {
            $lockedExtraction = ImageExtraction::query()->lockForUpdate()->find($this->imageExtractionId);

            if ($lockedExtraction === null || $lockedExtraction->status === ImageExtractionStatus::Processed) {
                return null;
            }

            $lockedExtraction->update([
                'status' => ImageExtractionStatus::Processing,
                'processing_error' => null,
                'processing_started_at' => now(),
            ]);

            return $lockedExtraction;
        });

        if ($extraction === null) {
            return;
        }

        $source = $extraction->sources()
            ->whereNotNull('file_path')
            ->oldest('id')
            ->first();

        if ($source === null || $source->file_path === null || ! Storage::disk('local')->exists($source->file_path)) {
            throw new RuntimeException('Original image is missing from private storage.');
        }

        $source->load('event');
        $harnessRun = $harnessRecorder->start(
            event: $source->event,
            type: HarnessRunType::ImageExtraction,
            correlationId: 'image-'.$this->imageExtractionId,
            metadata: [
                'image_extraction_id' => $this->imageExtractionId,
                'source_id' => $source->id,
                'mime_type' => $source->mime_type,
                'bytes' => Storage::disk('local')->size($source->file_path),
                'sha256' => $source->content_hash,
            ],
        );
        $harnessRecorder->append(
            run: $harnessRun,
            kind: HarnessEntryKind::Action,
            title: 'Зображення передано на розбір',
        );

        $result = $analysis->extractImage(
            Storage::disk('local')->get($source->file_path),
            $source->mime_type ?? 'image/jpeg',
            $harnessRun,
        );

        $activeTasks = DB::transaction(function () use ($result): array {
            $lockedExtraction = ImageExtraction::query()
                ->lockForUpdate()
                ->findOrFail($this->imageExtractionId);

            $lockedExtraction->update([
                'status' => ImageExtractionStatus::Processed,
                'classification' => $result->classification,
                'ocr_text' => $result->ocrText,
                'message_timeline' => $result->messageTimeline,
                'source_summary' => $result->summary,
                'dismissal_reason' => $result->dismissalReason,
                'processing_error' => null,
                'processed_at' => now(),
            ]);

            $tasks = [];
            $sources = $lockedExtraction->sources()->with('event')->lockForUpdate()->get();

            foreach ($sources as $relatedSource) {
                $newInclusion = $relatedSource->inclusion;

                if (
                    $result->classification === ImageClassification::Irrelevant
                    && $relatedSource->inclusion === EventSourceInclusion::Included
                ) {
                    $newInclusion = EventSourceInclusion::Dismissed;
                    $relatedSource->event->increment('evidence_version');
                }

                $relatedSource->update([
                    'status' => EventSourceStatus::Processed,
                    'inclusion' => $newInclusion,
                    'processing_error' => null,
                    'processed_at' => now(),
                ]);

                $event = $relatedSource->event->refresh();

                if ($event->hasActiveAnalysis()) {
                    $tasks[$event->id] = $event->analysis_task_id;
                }
            }

            return $tasks;
        });

        $this->dispatchActiveTasks($activeTasks);
        $harnessRecorder->append(
            run: $harnessRun,
            kind: HarnessEntryKind::Action,
            title: 'Розбір зображення збережено',
            message: 'Класифікація: '.$result->classification->value,
        );
        $harnessRecorder->finish($harnessRun);
    }

    public function failed(?Throwable $exception): void
    {
        $eventIds = ImageExtraction::query()
            ->find($this->imageExtractionId)
            ?->sources()
            ->pluck('event_id')
            ->all() ?? [];

        HarnessRun::query()
            ->whereIn('event_id', $eventIds)
            ->where('type', HarnessRunType::ImageExtraction)
            ->where('correlation_id', 'image-'.$this->imageExtractionId)
            ->update([
                'status' => HarnessRunStatus::Failed,
                'error' => mb_substr($exception?->getMessage() ?? 'Image extraction failed.', 0, 2000),
                'finished_at' => now(),
            ]);

        $activeTasks = DB::transaction(function () use ($exception): array {
            $extraction = ImageExtraction::query()->lockForUpdate()->find($this->imageExtractionId);

            if ($extraction === null) {
                return [];
            }

            $message = mb_substr($exception?->getMessage() ?? 'Image extraction failed.', 0, 2000);
            $extraction->update([
                'status' => ImageExtractionStatus::Failed,
                'processing_error' => $message,
                'processed_at' => now(),
            ]);

            $tasks = [];

            foreach ($extraction->sources()->with('event')->lockForUpdate()->get() as $source) {
                $source->update([
                    'status' => EventSourceStatus::Failed,
                    'processing_error' => $message,
                    'processed_at' => now(),
                ]);

                if ($source->event->hasActiveAnalysis()) {
                    $tasks[$source->event_id] = $source->event->analysis_task_id;
                }
            }

            return $tasks;
        });

        $this->dispatchActiveTasks($activeTasks);
    }

    /** @param array<int, string> $tasks */
    private function dispatchActiveTasks(array $tasks): void
    {
        foreach ($tasks as $eventId => $taskId) {
            SummarizeEventContextJob::dispatch($eventId, $taskId)
                ->delay(now()->addSeconds(5))
                ->afterCommit();
        }
    }
}
