<?php

namespace App\Actions;

use App\CartSyncStatus;
use App\EventSourceStatus;
use App\EventSourceType;
use App\EventStatus;
use App\Models\Event;
use App\Models\EventSource;
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

        try {
            return DB::transaction(function () use ($event, $text, $images, $batch, &$storedPaths): int {
                $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
                $existingSources = $lockedEvent->sources()->get()->keyBy('content_hash');
                $created = 0;
                $position = 0;

                if ($text !== null && $text !== '') {
                    $textHash = hash('sha256', $text);

                    $existingSource = $existingSources->get($textHash);

                    if ($existingSource === null) {
                        EventSource::query()->create([
                            'event_id' => $lockedEvent->id,
                            'type' => EventSourceType::Text,
                            'text' => $text,
                            'upload_batch' => $batch,
                            'position' => $position++,
                            'content_hash' => $textHash,
                            'status' => EventSourceStatus::Pending,
                        ]);

                        $created++;
                    } elseif ($this->retryFailedSource($existingSource)) {
                        $created++;
                    }
                }

                foreach ($images as $image) {
                    $imageHash = hash_file('sha256', $image->getRealPath());

                    $existingSource = $existingSources->get($imageHash);

                    if ($existingSource !== null) {
                        if ($this->retryFailedSource($existingSource)) {
                            $created++;
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

                    EventSource::query()->create([
                        'event_id' => $lockedEvent->id,
                        'type' => EventSourceType::Image,
                        'file_path' => $path,
                        'original_name' => $image->getClientOriginalName(),
                        'mime_type' => $image->getMimeType(),
                        'size' => $image->getSize(),
                        'upload_batch' => $batch,
                        'position' => $position++,
                        'content_hash' => $imageHash,
                        'status' => EventSourceStatus::Pending,
                    ]);

                    $created++;
                }

                if ($created > 0) {
                    $lockedEvent->update([
                        'status' => EventStatus::Processing,
                        'analysis_error' => null,
                        'cart_sync_status' => $lockedEvent->cart_synced_at === null
                            ? CartSyncStatus::NotSynced
                            : CartSyncStatus::Stale,
                        'last_source_at' => now(),
                    ]);
                }

                return $created;
            });
        } catch (Throwable $throwable) {
            Storage::disk('local')->delete($storedPaths);

            throw $throwable;
        }
    }

    private function retryFailedSource(EventSource $source): bool
    {
        if ($source->status !== EventSourceStatus::Failed) {
            return false;
        }

        $source->update([
            'status' => EventSourceStatus::Pending,
            'processing_error' => null,
            'processed_at' => null,
        ]);

        return true;
    }
}
