<?php

namespace App\Actions;

use App\CartRunMode;
use App\CartRunPhase;
use App\CartRunStatus;
use App\CartSyncStatus;
use App\Contracts\SilpoCartGateway;
use App\Exceptions\SilpoCartUnavailableException;
use App\HarnessEntryKind;
use App\HarnessRunType;
use App\Jobs\AdvanceEventCartRunJob;
use App\Models\Event;
use App\Models\EventCartRun;
use App\Services\GooseCartStatusService;
use App\Services\HarnessRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class StartEventCartRunAction
{
    public function __construct(
        private readonly SilpoCartGateway $silpo,
        private readonly GooseCartStatusService $statuses,
        private readonly HarnessRecorder $harnessRecorder,
    ) {}

    public function execute(Event $event, CartRunMode $mode): EventCartRun
    {
        $activeRun = $event->cartRuns()
            ->whereIn('status', $this->activeStatuses())
            ->latest()
            ->first();

        if ($activeRun !== null) {
            return $activeRun;
        }

        $this->guardPlan($event);
        $connection = $event->user()->firstOrFail()->silpoConnection()
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null || ($connection->expires_at !== null && $connection->expires_at->isPast())) {
            throw new SilpoCartUnavailableException(
                'connection_missing',
                'Гусь загубив звʼязок із Сільпо. Підключіть його ще раз.',
            );
        }

        $harnessRun = $this->harnessRecorder->start(
            event: $event,
            type: HarnessRunType::SilpoCart,
            correlationId: (string) Str::ulid(),
            metadata: ['mode' => $mode->value, 'plan_state_version' => $event->state_version],
        );

        try {
            $cart = $this->silpo->getReadyCart($connection->access_token, $harnessRun);
            $catalogScopes = $cart === null
                ? ['categories' => [], 'sets' => []]
                : $this->silpo->getCatalogScopes($connection->access_token, $cart, $harnessRun);
        } catch (Throwable $throwable) {
            $this->harnessRecorder->fail($harnessRun, $throwable);

            throw $throwable;
        }

        if ($cart === null) {
            $this->harnessRecorder->append(
                run: $harnessRun,
                kind: HarnessEntryKind::Action,
                title: 'Кошик не готовий до синхронізації',
            );
            $this->harnessRecorder->finish($harnessRun);
            throw new SilpoCartUnavailableException(
                'cart_missing',
                'Гусь без маршруту загубиться. Зайдіть у Сільпо, створіть кошик та оберіть адресу доставки і спосіб отримання.',
            );
        }

        return DB::transaction(function () use ($event, $mode, $cart, $catalogScopes, $harnessRun): EventCartRun {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            $this->guardPlan($lockedEvent);

            $activeRun = $lockedEvent->cartRuns()
                ->whereIn('status', $this->activeStatuses())
                ->latest()
                ->first();

            if ($activeRun !== null) {
                return $activeRun;
            }

            $run = $lockedEvent->cartRuns()->create([
                'harness_run_id' => $harnessRun->id,
                'mode' => $mode,
                'status' => CartRunStatus::Running,
                'phase' => CartRunPhase::Preparing,
                'plan_state_version' => $lockedEvent->state_version,
                'cursor' => 0,
                'cart_id' => $cart->cartId,
                'delivery_fingerprint' => $cart->fingerprint(),
                'cart_context' => $cart->toRunContext(),
                'state' => [
                    'event_context' => $lockedEvent->state,
                    'plan_snapshot' => $lockedEvent->shopping_plan,
                    'needs' => [],
                    'catalog_scopes' => $catalogScopes,
                    'current_need_index' => 0,
                    'last_candidates' => [],
                    'last_details' => null,
                    'final_audit' => null,
                    'blocked_phase' => null,
                ],
                'staged_items' => [],
                'warnings' => [],
                'started_at' => now(),
            ]);

            $this->statuses->append($run, 'preflight');
            $this->statuses->append($run, 'planning');
            $lockedEvent->update([
                'silpo_cart_id' => $cart->cartId,
                'cart_sync_status' => CartSyncStatus::Syncing,
                'cart_sync_error' => null,
            ]);

            AdvanceEventCartRunJob::dispatch($run->id, $run->cursor)->afterCommit();

            return $run;
        });
    }

    /** @return array<int, string> */
    private function activeStatuses(): array
    {
        return [
            CartRunStatus::Running->value,
            CartRunStatus::WaitingForAnswer->value,
            CartRunStatus::WaitingForConfirmation->value,
            CartRunStatus::Committing->value,
        ];
    }

    private function guardPlan(Event $event): void
    {
        $hasBlockingQuestion = collect(data_get($event->state, 'unresolved_questions', []))
            ->contains(fn (mixed $question): bool => is_array($question) && data_get($question, 'blocking') === true);

        if (! $event->isPlanCurrent() || $hasBlockingQuestion) {
            throw new RuntimeException('Гусь не може збирати кошик, доки список не готовий і важливі питання не закриті.');
        }
    }
}
