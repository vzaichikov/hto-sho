<?php

namespace App\Actions;

use App\CartRunPhase;
use App\CartRunStatus;
use App\HarnessEntryKind;
use App\Jobs\AdvanceEventCartRunJob;
use App\Models\EventCartRun;
use App\Services\GooseCartStatusService;
use App\Services\HarnessRecorder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ContinueEventCartRunAction
{
    public function __construct(
        private readonly GooseCartStatusService $statuses,
        private readonly HarnessRecorder $harnessRecorder,
    ) {}

    public function execute(EventCartRun $run, string $answer): EventCartRun
    {
        return DB::transaction(function () use ($run, $answer): EventCartRun {
            $lockedRun = EventCartRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($lockedRun->status !== CartRunStatus::WaitingForAnswer) {
                throw new RuntimeException('Гусь уже не чекає на відповідь для цього кроку.');
            }

            $question = $lockedRun->blocker;
            $harnessRun = $lockedRun->harnessRun()->first();

            if ($harnessRun !== null) {
                $this->harnessRecorder->append(
                    run: $harnessRun,
                    kind: HarnessEntryKind::Answer,
                    title: $question ?? 'Відповідь організатора',
                    message: $answer,
                    metadata: ['cart_run_id' => $lockedRun->id],
                );
            }

            $state = $lockedRun->state;
            $currentIndex = (int) data_get($state, 'current_need_index', 0);

            if (isset($state['needs'][$currentIndex])) {
                $state['needs'][$currentIndex]['human_answer'] = $answer;
            } else {
                $state['audit_answer'] = $answer;
            }

            $nextPhase = CartRunPhase::tryFrom((string) data_get($state, 'blocked_phase'))
                ?? CartRunPhase::Deciding;
            $state['blocked_phase'] = null;
            $lockedRun->update([
                'status' => CartRunStatus::Running,
                'phase' => $nextPhase,
                'state' => $state,
                'blocker' => null,
                'error' => null,
                'cursor' => $lockedRun->cursor + 1,
            ]);
            $this->statuses->append($lockedRun, 'planning');
            AdvanceEventCartRunJob::dispatch($lockedRun->id, $lockedRun->cursor)->afterCommit();

            return $lockedRun;
        });
    }
}
