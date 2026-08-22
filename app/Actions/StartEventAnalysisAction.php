<?php

namespace App\Actions;

use App\EventAnalysisStage;
use App\EventStatus;
use App\Jobs\SummarizeEventContextJob;
use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StartEventAnalysisAction
{
    public function execute(Event $event): Event
    {
        $event = DB::transaction(function () use ($event): Event {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);

            if ($lockedEvent->hasActiveAnalysis()) {
                return $lockedEvent;
            }

            $lockedEvent->update([
                'status' => EventStatus::Processing,
                'analysis_task_id' => (string) Str::ulid(),
                'analysis_stage' => EventAnalysisStage::WaitingForQuiet,
                'analysis_error' => null,
                'analysis_started_at' => now(),
                'analysis_finished_at' => null,
            ]);

            return $lockedEvent;
        });

        SummarizeEventContextJob::dispatch($event->id, $event->analysis_task_id)
            ->delay(now()->addSeconds(5))
            ->afterCommit();

        return $event;
    }
}
