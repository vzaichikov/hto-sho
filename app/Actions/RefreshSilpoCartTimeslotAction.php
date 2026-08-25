<?php

namespace App\Actions;

use App\CartRunStatus;
use App\Contracts\SilpoCartGateway;
use App\Data\SilpoCartContextData;
use App\HarnessRunType;
use App\Models\Event;
use App\Models\User;
use App\Services\HarnessRecorder;
use App\Services\SilpoCartLock;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class RefreshSilpoCartTimeslotAction
{
    public function __construct(
        private readonly SilpoCartGateway $silpo,
        private readonly HarnessRecorder $harnessRecorder,
        private readonly SilpoCartLock $lock,
    ) {}

    /** @param array{route_fingerprint: string, current_slot_fingerprint: string, slot_start: string, slot_end: string} $input */
    public function execute(User $user, Event $event, array $input): ?SilpoCartContextData
    {
        if ($user->cartRuns()->whereIn('event_cart_runs.status', $this->activeStatuses())->exists()) {
            throw new RuntimeException('Гусь уже працює з цим кошиком. Спершу завершіть або перезапустіть поточний збір.');
        }

        $connection = $user->silpoConnection()
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null || ($connection->expires_at !== null && $connection->expires_at->isPast())) {
            throw new RuntimeException('Звʼязок із Сільпо вже неактивний. Підключіть його ще раз і повторіть перевірку.');
        }

        return $this->lock->execute($user->id, function () use (
            $connection,
            $event,
            $input,
        ): ?SilpoCartContextData {
            $harnessRun = $this->harnessRecorder->start(
                event: $event,
                type: HarnessRunType::SilpoCart,
                correlationId: (string) Str::ulid(),
                metadata: ['action' => 'refresh_timeslot'],
            );

            try {
                $cart = $this->silpo->refreshCartTimeslot(
                    accessToken: $connection->access_token,
                    routeFingerprint: $input['route_fingerprint'],
                    currentSlotFingerprint: $input['current_slot_fingerprint'],
                    slotStart: $input['slot_start'],
                    slotEnd: $input['slot_end'],
                    harnessRun: $harnessRun,
                );
                $this->harnessRecorder->finish($harnessRun);

                return $cart;
            } catch (Throwable $throwable) {
                $this->harnessRecorder->fail($harnessRun, $throwable);

                throw $throwable;
            }
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
}
