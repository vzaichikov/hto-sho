<?php

namespace App\Actions;

use App\CartRunPhase;
use App\CartRunStatus;
use App\CartSyncStatus;
use App\Contracts\SilpoCartGateway;
use App\Data\SilpoCartContextData;
use App\Jobs\CommitEventCartRunJob;
use App\Models\EventCartRun;
use App\Services\GooseCartStatusService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use UnexpectedValueException;

final class ConfirmEventCartRunAction
{
    public function __construct(
        private readonly SilpoCartGateway $silpo,
        private readonly GooseCartStatusService $statuses,
    ) {}

    public function execute(EventCartRun $run): EventCartRun
    {
        $run->loadMissing(['event.user.silpoConnection', 'harnessRun']);

        if ($run->status !== CartRunStatus::WaitingForConfirmation
            || $run->phase !== CartRunPhase::ReadyToCommit) {
            throw new RuntimeException('Цей кошик уже не чекає на підтвердження. Оновіть сторінку.');
        }

        if (! $this->eventIsCurrent($run)) {
            $this->markStale($run, 'Список події змінився. Гусь нічого не додавав — запустіть його ще раз.');

            throw new RuntimeException((string) $run->refresh()->error);
        }

        $currentCart = $this->silpo->getReadyCart(
            $this->accessToken($run),
            $run->harnessRun,
        );

        return $this->confirmLocked($run, $currentCart);
    }

    private function confirmLocked(EventCartRun $run, ?SilpoCartContextData $currentCart): EventCartRun
    {
        [$confirmedRun, $error] = DB::transaction(function () use ($run, $currentCart): array {
            $lockedRun = EventCartRun::query()
                ->with('event')
                ->lockForUpdate()
                ->findOrFail($run->id);

            if ($lockedRun->status !== CartRunStatus::WaitingForConfirmation
                || $lockedRun->phase !== CartRunPhase::ReadyToCommit) {
                return [$lockedRun, 'Цей кошик уже не чекає на підтвердження. Оновіть сторінку.'];
            }

            if (! $this->eventIsCurrent($lockedRun)) {
                $message = 'Список події змінився. Гусь нічого не додавав — запустіть його ще раз.';
                $this->markLockedStale($lockedRun, $message);

                return [$lockedRun, $message];
            }

            if ($currentCart === null
                || $currentCart->cartId !== $lockedRun->cart_id
                || $currentCart->fingerprint() !== $lockedRun->delivery_fingerprint) {
                $message = 'Маршрут або час отримання в Сільпо змінився. Гусь нічого не додавав — перевірте кошик і запустіть його ще раз.';
                $this->markLockedStale($lockedRun, $message);

                return [$lockedRun, $message];
            }

            $lockedRun->update([
                'status' => CartRunStatus::Committing,
                'blocker' => null,
                'error' => null,
                'cursor' => $lockedRun->cursor + 1,
            ]);
            $this->statuses->append($lockedRun, 'confirmation');
            CommitEventCartRunJob::dispatch($lockedRun->id, $lockedRun->cursor)->afterCommit();

            return [$lockedRun, null];
        });

        if (is_string($error)) {
            throw new RuntimeException($error);
        }

        return $confirmedRun;
    }

    private function markStale(EventCartRun $run, string $message): void
    {
        DB::transaction(function () use ($run, $message): void {
            $lockedRun = EventCartRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($lockedRun->status !== CartRunStatus::WaitingForConfirmation) {
                return;
            }

            $this->markLockedStale($lockedRun, $message);
        });
    }

    private function markLockedStale(EventCartRun $run, string $message): void
    {
        $run->update([
            'status' => CartRunStatus::Stale,
            'phase' => CartRunPhase::Finished,
            'error' => $message,
            'finished_at' => now(),
            'cursor' => $run->cursor + 1,
        ]);
        $this->statuses->append($run, 'warning');
        $run->event()->update([
            'cart_sync_status' => CartSyncStatus::Stale,
            'cart_sync_error' => $message,
        ]);
    }

    private function eventIsCurrent(EventCartRun $run): bool
    {
        return $run->event->state_version === $run->plan_state_version
            && $run->event->isPlanCurrent();
    }

    private function accessToken(EventCartRun $run): string
    {
        $connection = $run->event->user->silpoConnection;

        if ($connection === null || $connection->revoked_at !== null
            || ($connection->expires_at !== null && $connection->expires_at->isPast())) {
            throw new UnexpectedValueException('Silpo connection is not available for cart confirmation.');
        }

        return $connection->access_token;
    }
}
