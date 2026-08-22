<?php

namespace App\Actions;

use App\CartSyncStatus;
use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use App\Models\Event;
use App\Models\EventSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreEventPlanCorrectionAction
{
    public function __construct(private readonly StartEventAnalysisAction $startAnalysis) {}

    public function execute(Event $event, int $planStateVersion, string $correction): int
    {
        $created = DB::transaction(function () use ($event, $planStateVersion, $correction): int {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            $contentHash = hash('sha256', implode('|', [
                'plan_correction',
                $planStateVersion,
                Str::lower(Str::squish($correction)),
            ]));

            if ($lockedEvent->sources()->where('content_hash', $contentHash)->exists()) {
                return 0;
            }

            if (! $lockedEvent->isPlanCurrent() || $lockedEvent->plan_state_version !== $planStateVersion) {
                throw ValidationException::withMessages([
                    'plan_state_version' => 'Гусь уже оновив список. Перегляньте свіжий варіант і повторіть корективу.',
                ]);
            }

            EventSource::query()->create([
                'event_id' => $lockedEvent->id,
                'type' => EventSourceType::Text,
                'origin' => 'plan_correction',
                'metadata' => [
                    'base_plan_state_version' => $lockedEvent->plan_state_version,
                    'base_plan' => $lockedEvent->shopping_plan,
                ],
                'text' => $correction,
                'upload_batch' => (string) Str::ulid(),
                'position' => 0,
                'content_hash' => $contentHash,
                'status' => EventSourceStatus::Processed,
                'inclusion' => EventSourceInclusion::Included,
                'processed_at' => now(),
            ]);

            $lockedEvent->update([
                'evidence_version' => $lockedEvent->evidence_version + 1,
                'cart_sync_status' => $lockedEvent->cart_synced_at === null
                    ? $lockedEvent->cart_sync_status
                    : CartSyncStatus::Stale,
                'last_source_at' => now(),
            ]);

            return 1;
        });

        if ($created > 0) {
            $this->startAnalysis->execute($event->fresh());
        }

        return $created;
    }
}
