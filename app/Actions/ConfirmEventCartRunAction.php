<?php

namespace App\Actions;

use App\CartHarnessMode;
use App\CartRunPhase;
use App\CartRunStatus;
use App\CartSyncStatus;
use App\Contracts\SilpoCartGateway;
use App\Data\SilpoCartContextData;
use App\Jobs\CommitAgenticEventCartRunJob;
use App\Jobs\CommitEventCartRunJob;
use App\Models\EventCartRun;
use App\Services\GooseCartStatusService;
use App\Services\SilpoCartLock;
use App\Services\SilpoCartResetGuard;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use UnexpectedValueException;

final class ConfirmEventCartRunAction
{
    public function __construct(
        private readonly SilpoCartGateway $silpo,
        private readonly GooseCartStatusService $statuses,
        private readonly SilpoCartResetGuard $resetGuard,
        private readonly SilpoCartLock $lock,
    ) {}

    public function execute(EventCartRun $run): EventCartRun
    {
        $run->loadMissing(['event.user.silpoConnection', 'harnessRun', 'silpoCartReset']);

        try {
            return $this->lock->execute(
                $run->event->user_id,
                fn (): EventCartRun => $this->confirm($run),
            );
        } catch (LockTimeoutException) {
            throw new RuntimeException('Гусь уже працює з цим кошиком. Дайте йому мить і спробуйте ще раз.');
        }
    }

    private function confirm(EventCartRun $run): EventCartRun
    {

        if ($run->status !== CartRunStatus::WaitingForConfirmation
            || $run->phase !== CartRunPhase::ReadyToCommit) {
            throw new RuntimeException('Цей кошик уже не чекає на підтвердження. Оновіть сторінку.');
        }

        if (! $this->eventIsCurrent($run)) {
            $this->markStale($run, 'Список події змінився. Гусь нічого не додавав — запустіть його ще раз.');

            throw new RuntimeException((string) $run->refresh()->error);
        }

        if ($run->silpoCartReset === null) {
            $this->markStale($run, 'Для цього старого походу немає підтвердженого очищення. Запустіть Гуся заново.');

            throw new RuntimeException((string) $run->refresh()->error);
        }

        $this->resetGuard->assertLatest($run->silpoCartReset, $run->event, allowConsumed: true);

        $currentCart = $this->silpo->getReadyCart(
            $this->accessToken($run),
            $run->harnessRun,
        );

        if ($currentCart !== null && ! $this->cartMayBeCommitted($run, $currentCart)) {
            $this->markStale($run, 'У кошику Сільпо зʼявилися сторонні товари. Гусь не змішуватиме їх із підтвердженим набором.');

            throw new RuntimeException((string) $run->refresh()->error);
        }

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
                'state' => [
                    ...$lockedRun->state,
                    'commit_attempts' => (int) data_get($lockedRun->state, 'commit_attempts', 0) + 1,
                ],
                'cursor' => $lockedRun->cursor + 1,
            ]);
            $this->statuses->append($lockedRun, 'confirmation');
            if ($lockedRun->harness_mode === CartHarnessMode::Agentic) {
                CommitAgenticEventCartRunJob::dispatch($lockedRun->id, $lockedRun->cursor)->afterCommit();
            } else {
                CommitEventCartRunJob::dispatch($lockedRun->id, $lockedRun->cursor)->afterCommit();
            }

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

    private function cartMayBeCommitted(EventCartRun $run, SilpoCartContextData $cart): bool
    {
        if ($cart->items === []) {
            return true;
        }

        if ((int) data_get($run->state, 'commit_attempts', 0) === 0) {
            return false;
        }

        $approvedProductIds = collect($run->staged_items ?? [])
            ->pluck('product_id')
            ->filter(fn (mixed $productId): bool => is_string($productId) && $productId !== '')
            ->unique();

        return collect($cart->items)->every(
            fn (array $item): bool => $approvedProductIds->containsStrict((string) data_get($item, 'product_id')),
        );
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
