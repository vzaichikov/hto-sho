<?php

namespace App\Actions;

use App\CartRunStatus;
use App\Exceptions\SilpoCartUnavailableException;
use App\Models\Event;
use App\Models\EventCartRun;
use App\Models\User;
use App\Services\SilpoFulfilmentTokenService;
use Illuminate\Support\Str;
use RuntimeException;

class PrepareSilpoCartResetAction
{
    public function __construct(private readonly SilpoFulfilmentTokenService $tokens) {}

    /** @return array<string, mixed> */
    public function execute(User $user, Event $event): array
    {
        $activeRun = $user->cartRuns()
            ->with('event')
            ->whereIn('event_cart_runs.status', $this->activeStatuses())
            ->latest('event_cart_runs.id')
            ->get()
            ->first(fn (EventCartRun $run): bool => $run->event !== null
                && $run->plan_state_version === $run->event->state_version
                && $run->event->isPlanCurrent());

        if ($activeRun !== null) {
            return [
                'ready' => true,
                'active_run_url' => route('events.cart-runs.show', [$activeRun->event, $activeRun]),
            ];
        }

        $this->guardPlan($event);
        $connection = $user->silpoConnection()->whereNull('revoked_at')->first();

        if ($connection === null || ($connection->expires_at !== null && $connection->expires_at->isPast())) {
            throw new SilpoCartUnavailableException(
                'connection_missing',
                'Гусь загубив звʼязок із Сільпо. Підключіть його ще раз.',
            );
        }

        return [
            'ready' => true,
            'reset_required' => true,
            'reset_url' => route('events.silpo.cart-reset', $event),
            'reset_token' => $this->tokens->issue('cart_reset', $user, $event, [
                'request_id' => (string) Str::ulid(),
                'plan_state_version' => $event->state_version,
            ]),
        ];
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
