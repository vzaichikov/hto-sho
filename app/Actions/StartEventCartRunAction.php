<?php

namespace App\Actions;

use App\CartHarnessMode;
use App\CartRunMode;
use App\CartRunPhase;
use App\CartRunStatus;
use App\CartSyncStatus;
use App\Contracts\SilpoCartGateway;
use App\Data\ConfirmedSilpoFulfilmentData;
use App\Exceptions\SilpoCartUnavailableException;
use App\HarnessEntryKind;
use App\HarnessRunType;
use App\Jobs\AdvanceAgenticEventCartRunJob;
use App\Jobs\AdvanceEventCartRunJob;
use App\Models\Event;
use App\Models\EventCartRun;
use App\Services\CartHarnessConfiguration;
use App\Services\GooseCartStatusService;
use App\Services\HarnessRecorder;
use App\Services\SilpoCartLock;
use App\Services\SilpoCartResetGuard;
use App\SilpoCartResetStatus;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
        private readonly SilpoCartResetGuard $resetGuard,
        private readonly SilpoCartLock $lock,
        private readonly CartHarnessConfiguration $harnessConfiguration,
    ) {}

    public function execute(
        Event $event,
        CartRunMode $mode,
        ConfirmedSilpoFulfilmentData $confirmed,
    ): EventCartRun {
        try {
            return $this->lock->execute(
                $event->user_id,
                fn (): EventCartRun => $this->start($event, $mode, $confirmed),
            );
        } catch (LockTimeoutException) {
            throw new RuntimeException('Гусь уже працює з цим кошиком. Дайте йому мить і спробуйте ще раз.');
        }
    }

    private function start(
        Event $event,
        CartRunMode $mode,
        ConfirmedSilpoFulfilmentData $confirmed,
    ): EventCartRun {
        $activeRun = $event->user->cartRuns()
            ->with('event')
            ->whereIn('event_cart_runs.status', $this->activeStatuses())
            ->latest('event_cart_runs.id')
            ->first();

        if ($activeRun !== null) {
            if ($activeRun->event_id === $event->id) {
                return $activeRun;
            }

            throw new RuntimeException('Інший похід Гуся ще активний. Завершіть його перед новим кошиком.');
        }

        $this->guardPlan($event);
        $reset = $this->resetGuard->assertLatest($confirmed->reset, $event);
        $connection = $event->user->silpoConnection()
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null || ($connection->expires_at !== null && $connection->expires_at->isPast())) {
            throw new SilpoCartUnavailableException(
                'connection_missing',
                'Гусь загубив звʼязок із Сільпо. Підключіть його ще раз.',
            );
        }

        $harnessMode = $this->harnessConfiguration->mode();
        $this->harnessConfiguration->assertReady($harnessMode);
        $harnessMetadata = [
            'mode' => $mode->value,
            'harness_mode' => $harnessMode->value,
            'plan_state_version' => $event->state_version,
        ];

        if ($harnessMode === CartHarnessMode::Agentic) {
            $harnessMetadata['configured_model'] = $this->harnessConfiguration->model();
            $harnessMetadata['reasoning_effort'] = $this->harnessConfiguration->reasoningEffort();
            $harnessMetadata['configured_reasoning_effort'] = $this->harnessConfiguration->reasoningEffort();
        }

        $harnessRun = $this->harnessRecorder->start(
            event: $event,
            type: HarnessRunType::SilpoCart,
            correlationId: (string) Str::ulid(),
            metadata: $harnessMetadata,
        );

        try {
            $cart = $this->silpo->getReadyCart($connection->access_token, $harnessRun);

            if ($cart !== null
                && ($cart->items !== []
                    || ! hash_equals($confirmed->cart->fingerprint(), $cart->fingerprint())
                    || ! hash_equals($reset->cart_id, $cart->cartId))) {
                throw new RuntimeException('Маршрут змінився просто перед стартом. Гусь просить перевірити його ще раз.');
            }

            $catalogScopes = $cart === null || $harnessMode === CartHarnessMode::Agentic
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

        return DB::transaction(function () use ($event, $mode, $harnessMode, $cart, $catalogScopes, $harnessRun, $reset): EventCartRun {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            $this->guardPlan($lockedEvent);

            $lockedReset = $lockedEvent->silpoCartResets()
                ->lockForUpdate()
                ->findOrFail($reset->id);
            $this->resetGuard->assertLatest($lockedReset, $lockedEvent);

            $lockedEvent->cartRuns()
                ->whereIn('status', $this->activeStatuses())
                ->where('plan_state_version', '!=', $lockedEvent->state_version)
                ->get()
                ->each(function (EventCartRun $staleRun): void {
                    $staleRun->update([
                        'status' => CartRunStatus::Stale,
                        'phase' => CartRunPhase::Finished,
                        'error' => 'Список події змінився. Цей кошик лишається в історії без запису в Сільпо.',
                        'finished_at' => now(),
                    ]);
                    $this->statuses->append($staleRun, 'warning');
                });

            $activeRun = $lockedEvent->cartRuns()
                ->whereIn('status', $this->activeStatuses())
                ->where('plan_state_version', $lockedEvent->state_version)
                ->latest()
                ->first();

            if ($activeRun !== null) {
                return $activeRun;
            }

            $run = $lockedEvent->cartRuns()->create([
                'silpo_cart_reset_id' => $lockedReset->id,
                'harness_run_id' => $harnessRun->id,
                'mode' => $mode,
                'harness_mode' => $harnessMode,
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
            $lockedReset->update([
                'status' => SilpoCartResetStatus::Consumed,
                'consumed_at' => now(),
            ]);
            $lockedEvent->update([
                'silpo_cart_id' => $cart->cartId,
                'cart_sync_status' => CartSyncStatus::Syncing,
                'cart_sync_error' => null,
            ]);

            if ($harnessMode === CartHarnessMode::Agentic) {
                AdvanceAgenticEventCartRunJob::dispatch($run->id, $run->cursor)->afterCommit();
            } else {
                AdvanceEventCartRunJob::dispatch($run->id, $run->cursor)->afterCommit();
            }

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
