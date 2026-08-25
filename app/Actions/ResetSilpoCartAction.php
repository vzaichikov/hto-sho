<?php

namespace App\Actions;

use App\CartRunPhase;
use App\CartRunStatus;
use App\Contracts\SilpoCartGateway;
use App\Data\SilpoFulfilmentSnapshotData;
use App\Exceptions\SilpoCartUnavailableException;
use App\HarnessRunType;
use App\Models\Event;
use App\Models\EventCartRun;
use App\Models\HarnessRun;
use App\Models\SilpoCartReset;
use App\Models\User;
use App\Services\GooseCartStatusService;
use App\Services\HarnessRecorder;
use App\Services\SilpoCartLock;
use App\Services\SilpoFulfilmentTokenService;
use App\SilpoCartResetStatus;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ResetSilpoCartAction
{
    public function __construct(
        private readonly SilpoCartGateway $silpo,
        private readonly SilpoFulfilmentTokenService $tokens,
        private readonly PrepareSilpoFulfilmentAction $prepareFulfilment,
        private readonly HarnessRecorder $harnessRecorder,
        private readonly SilpoCartLock $lock,
        private readonly GooseCartStatusService $statuses,
    ) {}

    /** @return array<string, mixed> */
    public function execute(User $user, Event $event, string $token): array
    {
        $payload = $this->tokens->decode($token, 'cart_reset', $user, $event);
        $requestId = Arr::get($payload, 'request_id');

        if (! is_string($requestId)
            || (int) Arr::get($payload, 'plan_state_version') !== $event->state_version
            || ! $event->isPlanCurrent()) {
            throw new RuntimeException('Список події змінився. Підтвердьте очищення кошика ще раз.');
        }

        $connection = $user->silpoConnection()->whereNull('revoked_at')->first();

        if ($connection === null || ($connection->expires_at !== null && $connection->expires_at->isPast())) {
            throw new SilpoCartUnavailableException(
                'connection_missing',
                'Гусь загубив звʼязок із Сільпо. Підключіть його ще раз.',
            );
        }

        try {
            return $this->lock->execute(
                $user->id,
                fn (): array => $this->reset(
                    $user,
                    $event,
                    $connection->access_token,
                    hash('sha256', $requestId),
                ),
            );
        } catch (LockTimeoutException) {
            throw new RuntimeException('Гусь уже працює з цим кошиком. Дайте йому мить і спробуйте ще раз.');
        }
    }

    /** @return array<string, mixed> */
    private function reset(User $user, Event $event, string $accessToken, string $requestKey): array
    {
        $this->expireInvalidRuns($user);

        $activeRun = $user->cartRuns()
            ->with('event')
            ->whereIn('event_cart_runs.status', $this->activeStatuses())
            ->latest('event_cart_runs.id')
            ->first();

        if ($activeRun !== null) {
            throw new RuntimeException('Інший похід Гуся ще активний. Завершіть його перед очищенням кошика.');
        }

        $harnessRun = $this->harnessRecorder->start(
            event: $event,
            type: HarnessRunType::SilpoPreflight,
            correlationId: (string) Str::ulid(),
            metadata: ['action' => 'cart_reset'],
        );

        try {
            $reset = SilpoCartReset::query()->where('request_key', $requestKey)->first();

            if ($reset !== null && ($reset->user_id !== $user->id || $reset->event_id !== $event->id)) {
                throw new RuntimeException('Це підтвердження очищення належить іншому походу Гуся.');
            }

            $reset = $reset === null
                ? $this->backupCurrentCart($user, $event, $accessToken, $requestKey, $harnessRun)
                : $this->resumeReset($reset, $event, $accessToken, $harnessRun);

            if ($reset->status !== SilpoCartResetStatus::Cleared) {
                $reset = $this->clearAndVerify($reset, $accessToken, $harnessRun);
            }

            $this->harnessRecorder->finish($harnessRun);

            return $this->prepareFulfilment->execute($user, $event, $reset);
        } catch (Throwable $throwable) {
            if ($harnessRun->finished_at === null) {
                $this->harnessRecorder->fail($harnessRun, $throwable);
            }

            throw $throwable;
        }
    }

    private function expireInvalidRuns(User $user): void
    {
        $user->cartRuns()
            ->with('event')
            ->whereIn('event_cart_runs.status', $this->activeStatuses())
            ->get()
            ->filter(fn (EventCartRun $run): bool => $run->event === null
                || $run->plan_state_version !== $run->event->state_version
                || ! $run->event->isPlanCurrent())
            ->each(function (EventCartRun $run): void {
                $run->update([
                    'status' => CartRunStatus::Stale,
                    'phase' => CartRunPhase::Finished,
                    'error' => 'Список події змінився. Цей похід лишається в історії без нового запису в Сільпо.',
                    'finished_at' => now(),
                ]);
                $this->statuses->append($run, 'warning');
            });
    }

    private function backupCurrentCart(
        User $user,
        Event $event,
        string $accessToken,
        string $requestKey,
        HarnessRun $harnessRun,
    ): SilpoCartReset {
        $snapshot = $this->silpo->getFulfilmentSnapshot($accessToken, $harnessRun);

        if ($snapshot === null) {
            throw new SilpoCartUnavailableException(
                'cart_missing',
                'У Сільпо ще немає кошика для Гуся. Відкрийте Сільпо, створіть кошик і повертайтеся.',
            );
        }

        return DB::transaction(function () use ($user, $event, $requestKey, $snapshot): SilpoCartReset {
            SilpoCartReset::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [SilpoCartResetStatus::Cleared->value, SilpoCartResetStatus::Consumed->value])
                ->update(['status' => SilpoCartResetStatus::Superseded->value]);

            return SilpoCartReset::query()->create([
                'user_id' => $user->id,
                'event_id' => $event->id,
                'plan_state_version' => $event->state_version,
                'request_key' => $requestKey,
                'status' => SilpoCartResetStatus::Pending,
                'cart_id' => $snapshot->cartId,
                'before_cart_fingerprint' => $snapshot->cartFingerprint(),
                'before_product_fingerprint' => $snapshot->productFingerprint(),
                'items_count' => $snapshot->itemsCount(),
                'total' => $snapshot->totalAfterDiscounts(),
                'snapshot' => ['cart_id' => $snapshot->cartId, 'cart' => $snapshot->cart],
            ]);
        });
    }

    private function resumeReset(
        SilpoCartReset $reset,
        Event $event,
        string $accessToken,
        HarnessRun $harnessRun,
    ): SilpoCartReset {
        if ($reset->plan_state_version !== $event->state_version
            || in_array($reset->status, [SilpoCartResetStatus::Consumed, SilpoCartResetStatus::Superseded], true)) {
            throw new RuntimeException('Це очищення вже неактуальне. Підтвердьте новий старт Гуся.');
        }

        $snapshot = $this->silpo->getFulfilmentSnapshot($accessToken, $harnessRun);

        if ($snapshot === null || ! hash_equals($reset->cart_id, $snapshot->cartId)) {
            throw new RuntimeException('Кошик Сільпо змінився. Підтвердьте очищення нового кошика.');
        }

        if ($snapshot->isEmpty()) {
            return $this->markCleared($reset, $snapshot);
        }

        if ($reset->status === SilpoCartResetStatus::Cleared) {
            $reset->update([
                'status' => SilpoCartResetStatus::Superseded,
                'error' => 'Після підтвердженого очищення в кошику зʼявилися товари; повторне очищення заборонено.',
            ]);

            throw new RuntimeException('Після очищення в кошику зʼявилися товари. Гусь не видалятиме їх за старим дозволом.');
        }

        if (! hash_equals($reset->before_cart_fingerprint, $snapshot->cartFingerprint())) {
            $reset->update([
                'status' => SilpoCartResetStatus::Superseded,
                'error' => 'Кошик змінився після збереження копії; повторне очищення заборонено.',
            ]);

            throw new RuntimeException('Кошик змінився після збереження копії. Гусь не очищатиме новий вміст без дозволу.');
        }

        return $reset;
    }

    private function clearAndVerify(
        SilpoCartReset $reset,
        string $accessToken,
        HarnessRun $harnessRun,
    ): SilpoCartReset {
        try {
            $snapshot = $this->silpo->clearCartProducts($accessToken, $reset->cart_id, $harnessRun);
        } catch (Throwable $throwable) {
            $snapshot = $this->silpo->getFulfilmentSnapshot($accessToken, $harnessRun);

            if ($snapshot === null
                || ! hash_equals($reset->cart_id, $snapshot->cartId)
                || ! $snapshot->isEmpty()) {
                $reset->update([
                    'status' => SilpoCartResetStatus::Failed,
                    'error' => $throwable->getMessage(),
                ]);

                throw $throwable;
            }
        }

        return $this->markCleared($reset, $snapshot);
    }

    private function markCleared(
        SilpoCartReset $reset,
        SilpoFulfilmentSnapshotData $snapshot,
    ): SilpoCartReset {
        if (! $snapshot->isEmpty()) {
            throw new RuntimeException('Сільпо не підтвердило порожній кошик. Гусь зупинився.');
        }

        $reset->update([
            'status' => SilpoCartResetStatus::Cleared,
            'empty_product_fingerprint' => $snapshot->productFingerprint(),
            'error' => null,
            'cleared_at' => now(),
        ]);

        return $reset->refresh();
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
}
