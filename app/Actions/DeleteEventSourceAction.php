<?php

namespace App\Actions;

use App\CartSyncStatus;
use App\Jobs\SummarizeEventContextJob;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\ImageExtraction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteEventSourceAction
{
    public function execute(Event $event, EventSource $source): void
    {
        $path = $source->file_path;
        $activeTask = null;

        DB::transaction(function () use ($event, $source, &$activeTask): void {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            $lockedSource = EventSource::query()
                ->whereBelongsTo($lockedEvent)
                ->lockForUpdate()
                ->findOrFail($source->id);
            $extractionId = $lockedSource->image_extraction_id;
            $changesEvidence = $lockedSource->isIncluded();

            $lockedSource->delete();

            if (
                $extractionId !== null
                && ! EventSource::query()->where('image_extraction_id', $extractionId)->exists()
            ) {
                ImageExtraction::query()->whereKey($extractionId)->delete();
            }

            $lockedEvent->update([
                'evidence_version' => $lockedEvent->evidence_version + (int) $changesEvidence,
                'cart_sync_status' => $changesEvidence && $lockedEvent->cart_synced_at !== null
                    ? CartSyncStatus::Stale
                    : $lockedEvent->cart_sync_status,
                'last_source_at' => now(),
            ]);

            if ($lockedEvent->hasActiveAnalysis()) {
                $activeTask = [$lockedEvent->id, $lockedEvent->analysis_task_id];
            }
        });

        if ($path !== null) {
            Storage::disk('local')->delete($path);
        }

        if ($activeTask !== null) {
            SummarizeEventContextJob::dispatch($activeTask[0], $activeTask[1])
                ->delay(now()->addSeconds(5))
                ->afterCommit();
        }
    }
}
