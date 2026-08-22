<?php

namespace App\Actions;

use App\CartSyncStatus;
use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use App\ImageClassification;
use App\ImageExtractionStatus;
use App\Jobs\ProcessImageExtractionJob;
use App\Jobs\SummarizeEventContextJob;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\ImageExtraction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StoreEventSourcesAction
{
    /**
     * @param  array<int, UploadedFile>  $images
     */
    public function execute(Event $event, ?string $text, array $images): int
    {
        $batch = (string) Str::ulid();
        $storedPaths = [];
        $extractionIds = [];
        $activeTask = null;

        try {
            $created = DB::transaction(function () use (
                $event,
                $text,
                $images,
                $batch,
                &$storedPaths,
                &$extractionIds,
                &$activeTask,
            ): int {
                $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
                $existingSources = $lockedEvent->sources()->get()->keyBy('content_hash');
                $created = 0;
                $evidenceChanges = 0;
                $position = 0;

                if ($text !== null && $text !== '') {
                    $textHash = hash('sha256', $text);
                    $existingSource = $existingSources->get($textHash);

                    if ($existingSource === null) {
                        $source = EventSource::query()->create([
                            'event_id' => $lockedEvent->id,
                            'type' => EventSourceType::Text,
                            'text' => $text,
                            'upload_batch' => $batch,
                            'position' => $position++,
                            'content_hash' => $textHash,
                            'status' => EventSourceStatus::Processed,
                            'inclusion' => EventSourceInclusion::Included,
                            'processed_at' => now(),
                        ]);
                        $existingSources->put($textHash, $source);
                        $created++;
                        $evidenceChanges++;
                    }
                }

                foreach ($images as $image) {
                    $imageHash = hash_file('sha256', $image->getRealPath());
                    $existingSource = $existingSources->get($imageHash);

                    if ($existingSource !== null) {
                        if ($existingSource->status === EventSourceStatus::Failed) {
                            $this->resetFailedExtraction($existingSource, $extractionIds);
                            $created++;
                            $evidenceChanges++;
                        }

                        continue;
                    }

                    $path = $image->storeAs(
                        "events/{$lockedEvent->user_id}/{$lockedEvent->id}",
                        $image->hashName(),
                        'local',
                    );

                    if (! is_string($path)) {
                        throw new RuntimeException('Не вдалося зберегти зображення.');
                    }

                    $storedPaths[] = $path;
                    $extraction = ImageExtraction::query()->firstOrCreate(
                        [
                            'user_id' => $lockedEvent->user_id,
                            'content_hash' => $imageHash,
                        ],
                        ['status' => ImageExtractionStatus::Pending],
                    );

                    if ($extraction->status === ImageExtractionStatus::Failed) {
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
                    }

                    $isCached = ! $extraction->wasRecentlyCreated
                        && $extraction->status === ImageExtractionStatus::Processed;
                    $inclusion = $extraction->classification === ImageClassification::Irrelevant
                        ? EventSourceInclusion::Dismissed
                        : EventSourceInclusion::Included;
                    $status = match ($extraction->status) {
                        ImageExtractionStatus::Processed => EventSourceStatus::Processed,
                        ImageExtractionStatus::Processing => EventSourceStatus::Processing,
                        default => EventSourceStatus::Pending,
                    };

                    $source = EventSource::query()->create([
                        'event_id' => $lockedEvent->id,
                        'image_extraction_id' => $extraction->id,
                        'type' => EventSourceType::Image,
                        'file_path' => $path,
                        'original_name' => $image->getClientOriginalName(),
                        'mime_type' => $image->getMimeType(),
                        'size' => $image->getSize(),
                        'upload_batch' => $batch,
                        'position' => $position++,
                        'content_hash' => $imageHash,
                        'status' => $status,
                        'inclusion' => $inclusion,
                        'used_cached_extraction' => $isCached,
                        'processed_at' => $status === EventSourceStatus::Processed
                            ? $extraction->processed_at
                            : null,
                    ]);
                    $existingSources->put($imageHash, $source);

                    if ($extraction->status !== ImageExtractionStatus::Processed) {
                        $extractionIds[$extraction->id] = $extraction->id;
                    }

                    $created++;

                    if ($inclusion->isIncluded()) {
                        $evidenceChanges++;
                    }
                }

                if ($created > 0) {
                    $lockedEvent->update([
                        'evidence_version' => $lockedEvent->evidence_version + $evidenceChanges,
                        'analysis_error' => $lockedEvent->hasActiveAnalysis()
                            ? null
                            : $lockedEvent->analysis_error,
                        'cart_sync_status' => $evidenceChanges > 0 && $lockedEvent->cart_synced_at !== null
                            ? CartSyncStatus::Stale
                            : $lockedEvent->cart_sync_status,
                        'last_source_at' => now(),
                    ]);

                    if ($lockedEvent->hasActiveAnalysis()) {
                        $activeTask = [$lockedEvent->id, $lockedEvent->analysis_task_id];
                    }
                }

                return $created;
            });
        } catch (Throwable $throwable) {
            Storage::disk('local')->delete($storedPaths);

            throw $throwable;
        }

        foreach ($extractionIds as $extractionId) {
            ProcessImageExtractionJob::dispatch($extractionId)->afterCommit();
        }

        if ($activeTask !== null) {
            SummarizeEventContextJob::dispatch($activeTask[0], $activeTask[1])
                ->delay(now()->addSeconds(5))
                ->afterCommit();
        }

        return $created;
    }

    /** @param array<int, int> $extractionIds */
    private function resetFailedExtraction(EventSource $source, array &$extractionIds): void
    {
        $extraction = $source->imageExtraction;

        if ($extraction === null) {
            return;
        }

        $extraction->update([
            'status' => ImageExtractionStatus::Pending,
            'processing_error' => null,
            'processing_started_at' => null,
            'processed_at' => null,
        ]);
        $source->update([
            'status' => EventSourceStatus::Pending,
            'processing_error' => null,
            'processed_at' => null,
        ]);
        $extractionIds[$extraction->id] = $extraction->id;
    }
}
