<?php

namespace App\Actions;

use App\CartSyncStatus;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

class UpdateEventAction
{
    public function __construct(private readonly StartEventAnalysisAction $startAnalysis) {}

    /**
     * @param  array{title: string, description?: ?string, people_count?: ?int, budget_amount?: int|float|string|null}  $attributes
     */
    public function execute(Event $event, array $attributes): Event
    {
        [$event, $changed] = DB::transaction(function () use ($event, $attributes): array {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            $lockedEvent->fill($attributes);
            $changed = $lockedEvent->isDirty(['title', 'description', 'people_count', 'budget_amount']);

            if ($changed) {
                $lockedEvent->evidence_version++;

                if ($lockedEvent->cart_synced_at !== null) {
                    $lockedEvent->cart_sync_status = CartSyncStatus::Stale;
                }
            }

            $lockedEvent->save();

            return [$lockedEvent, $changed];
        });

        return $changed ? $this->startAnalysis->execute($event) : $event;
    }
}
