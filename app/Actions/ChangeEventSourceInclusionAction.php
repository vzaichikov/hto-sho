<?php

namespace App\Actions;

use App\CartSyncStatus;
use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use App\ImageClassification;
use App\Models\Event;
use App\Models\EventSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeEventSourceInclusionAction
{
    public function __construct(private readonly StartEventAnalysisAction $startAnalysis) {}

    public function execute(Event $event, EventSource $source, EventSourceInclusion $inclusion): EventSource
    {
        $changed = false;

        $source = DB::transaction(function () use ($event, $source, $inclusion, &$changed): EventSource {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            $lockedSource = EventSource::query()
                ->with('imageExtraction')
                ->whereBelongsTo($lockedEvent)
                ->lockForUpdate()
                ->findOrFail($source->id);

            if (
                $lockedSource->type !== EventSourceType::Image
                || $lockedSource->status !== EventSourceStatus::Processed
                || $lockedSource->imageExtraction?->classification !== ImageClassification::Irrelevant
            ) {
                throw ValidationException::withMessages([
                    'inclusion' => 'Змінити рішення можна лише для обробленого нерелевантного зображення.',
                ]);
            }

            if ($lockedSource->inclusion === $inclusion) {
                return $lockedSource;
            }

            $lockedSource->update(['inclusion' => $inclusion]);
            $changed = true;
            $lockedEvent->update([
                'evidence_version' => $lockedEvent->evidence_version + 1,
                'cart_sync_status' => $lockedEvent->cart_synced_at === null
                    ? $lockedEvent->cart_sync_status
                    : CartSyncStatus::Stale,
                'last_source_at' => now(),
            ]);

            return $lockedSource;
        });

        if ($changed) {
            $this->startAnalysis->execute($event->fresh());
        }

        return $source;
    }
}
