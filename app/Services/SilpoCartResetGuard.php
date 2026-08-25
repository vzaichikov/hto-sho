<?php

namespace App\Services;

use App\Data\SilpoFulfilmentSnapshotData;
use App\Models\Event;
use App\Models\SilpoCartReset;
use App\Models\User;
use App\SilpoCartResetStatus;
use Illuminate\Support\Arr;
use RuntimeException;

class SilpoCartResetGuard
{
    public function __construct(private readonly SilpoFulfilmentTokenService $tokens) {}

    public function fromToken(
        string $token,
        User $user,
        Event $event,
        string $purpose = 'cart_reset_verified',
    ): SilpoCartReset {
        $payload = $this->tokens->decode($token, $purpose, $user, $event);

        return $this->fromPayload($payload, $user, $event);
    }

    /** @param array<string, mixed> $payload */
    public function fromPayload(array $payload, User $user, Event $event): SilpoCartReset
    {
        $resetId = Arr::get($payload, 'reset_id');
        $emptyFingerprint = Arr::get($payload, 'empty_product_fingerprint');

        if (! is_numeric($resetId) || ! is_string($emptyFingerprint)) {
            throw new RuntimeException('Підтвердження чистого кошика вже неактуальне. Почніть похід Гуся ще раз.');
        }

        $reset = SilpoCartReset::query()
            ->whereKey((int) $resetId)
            ->where('user_id', $user->id)
            ->where('event_id', $event->id)
            ->first();

        if ($reset === null || ! hash_equals((string) $reset->empty_product_fingerprint, $emptyFingerprint)) {
            throw new RuntimeException('Підтвердження чистого кошика вже неактуальне. Почніть похід Гуся ще раз.');
        }

        return $this->assertLatest($reset, $event);
    }

    public function assertLatest(SilpoCartReset $reset, Event $event, bool $allowConsumed = false): SilpoCartReset
    {
        $allowedStatuses = $allowConsumed
            ? [SilpoCartResetStatus::Cleared->value, SilpoCartResetStatus::Consumed->value]
            : [SilpoCartResetStatus::Cleared->value];
        $latestResetId = SilpoCartReset::query()
            ->where('user_id', $reset->user_id)
            ->whereIn('status', [SilpoCartResetStatus::Cleared->value, SilpoCartResetStatus::Consumed->value])
            ->latest('id')
            ->value('id');

        if (! in_array($reset->status->value, $allowedStatuses, true)
            || $latestResetId !== $reset->id
            || $reset->event_id !== $event->id
            || $reset->plan_state_version !== $event->state_version
            || ! $event->isPlanCurrent()) {
            throw new RuntimeException('Це очищення кошика вже неактуальне. Підтвердьте новий старт Гуся.');
        }

        return $reset;
    }

    public function assertEmptySnapshot(
        SilpoCartReset $reset,
        ?SilpoFulfilmentSnapshotData $snapshot,
    ): SilpoFulfilmentSnapshotData {
        if ($snapshot === null
            || ! hash_equals($reset->cart_id, $snapshot->cartId)
            || ! $snapshot->isEmpty()
            || ! is_string($reset->empty_product_fingerprint)
            || ! hash_equals($reset->empty_product_fingerprint, $snapshot->productFingerprint())) {
            if ($reset->status === SilpoCartResetStatus::Cleared) {
                $reset->update([
                    'status' => SilpoCartResetStatus::Superseded,
                    'error' => 'Кошик або його товари змінилися після підтвердженого очищення.',
                ]);
            }

            throw new RuntimeException('Кошик Сільпо вже не порожній або змінився. Потрібне нове підтвердження очищення.');
        }

        return $snapshot;
    }

    public function issueToken(SilpoCartReset $reset, User $user, Event $event): string
    {
        return $this->tokens->issue('cart_reset_verified', $user, $event, [
            'reset_id' => $reset->id,
            'plan_state_version' => $reset->plan_state_version,
            'empty_product_fingerprint' => $reset->empty_product_fingerprint,
        ]);
    }
}
