<?php

namespace App\Jobs;

use App\CartHarnessMode;
use App\Contracts\AgenticSilpoCartRunner;
use App\Contracts\SilpoCartGateway;
use App\Models\EventCartRun;
use App\Services\AgenticCommitSilpoCartGateway;
use App\Services\GooseCartStatusService;
use App\Services\SilpoCartLock;
use App\Services\SilpoCartResetGuard;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

class CommitAgenticEventCartRunJob extends CommitEventCartRunJob implements ShouldBeUnique, ShouldQueue
{
    public int $timeout = 170;

    public function __construct(
        int $runId,
        int $expectedCursor,
    ) {
        parent::__construct($runId, $expectedCursor);
    }

    public function uniqueId(): string
    {
        return $this->runId.':agentic-commit:'.$this->expectedCursor;
    }

    public function handle(
        SilpoCartGateway $silpo,
        GooseCartStatusService $statuses,
        ?SilpoCartResetGuard $resetGuard = null,
        ?SilpoCartLock $lock = null,
    ): void {
        $run = EventCartRun::query()->find($this->runId);

        if ($run === null || $run->harness_mode !== CartHarnessMode::Agentic) {
            return;
        }

        parent::handle(
            new AgenticCommitSilpoCartGateway($silpo, app(AgenticSilpoCartRunner::class)),
            $statuses,
            $resetGuard,
            $lock,
        );
    }
}
