<?php

namespace App\Actions;

use App\CartHarnessMode;
use App\CartRunPhase;
use App\CartRunStatus;
use App\HarnessEntryKind;
use App\Jobs\AdvanceAgenticEventCartRunJob;
use App\Jobs\AdvanceEventCartRunJob;
use App\Models\EventCartRun;
use App\Services\CartQuantityCalculator;
use App\Services\GooseCartStatusService;
use App\Services\HarnessRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ContinueEventCartRunAction
{
    public function __construct(
        private readonly GooseCartStatusService $statuses,
        private readonly HarnessRecorder $harnessRecorder,
        private readonly CartQuantityCalculator $quantities,
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
                    title: Str::limit($question ?? 'Відповідь організатора', 240),
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

            $blockedPhase = CartRunPhase::tryFrom((string) data_get($state, 'blocked_phase'));
            $nextPhase = $blockedPhase
                ?? CartRunPhase::Deciding;
            $requestedQuery = $this->requestedSearchQuery($answer);

            if (isset($state['needs'][$currentIndex])
                && in_array($blockedPhase, [CartRunPhase::Searching, CartRunPhase::Deciding], true)
                && $requestedQuery !== null) {
                $attemptedQueries = collect(data_get($state, "needs.{$currentIndex}.attempts", []))
                    ->pluck('query')
                    ->filter(fn (mixed $query): bool => is_string($query));

                if (! $attemptedQueries->contains($requestedQuery)) {
                    $state['needs'][$currentIndex]['search_query'] = $requestedQuery;
                    $state['needs'][$currentIndex]['assisted_search_pending'] = true;
                    $nextPhase = CartRunPhase::Searching;
                }
            }

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
            if ($lockedRun->harness_mode === CartHarnessMode::Agentic) {
                AdvanceAgenticEventCartRunJob::dispatch($lockedRun->id, $lockedRun->cursor)->afterCommit();
            } else {
                AdvanceEventCartRunJob::dispatch($lockedRun->id, $lockedRun->cursor)->afterCommit();
            }

            return $lockedRun;
        });
    }

    private function requestedSearchQuery(string $answer): ?string
    {
        if (preg_match('/^(?:шукай|шукати|пошук)\s*:\s*(.+)$/iu', trim($answer), $matches) !== 1) {
            return null;
        }

        $query = $this->quantities->normalizeSearchQuery($matches[1]);

        return $query !== '' ? $query : null;
    }
}
