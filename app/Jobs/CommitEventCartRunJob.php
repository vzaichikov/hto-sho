<?php

namespace App\Jobs;

use App\CartRunPhase;
use App\CartRunStatus;
use App\CartSyncStatus;
use App\Contracts\SilpoCartGateway;
use App\Data\SilpoCartContextData;
use App\Models\EventCartRun;
use App\Services\GooseCartStatusService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

class CommitEventCartRunJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 80;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $runId,
        public readonly int $expectedCursor,
    ) {
        $this->onQueue('ai-events');
    }

    public function uniqueId(): string
    {
        return $this->runId.':commit:'.$this->expectedCursor;
    }

    public function handle(SilpoCartGateway $silpo, GooseCartStatusService $statuses): void
    {
        $run = EventCartRun::query()
            ->with(['event.user.silpoConnection', 'harnessRun'])
            ->find($this->runId);

        if (! $this->canCommit($run)) {
            return;
        }

        if ($run->event->state_version !== $run->plan_state_version || ! $run->event->isPlanCurrent()) {
            $this->markStale($run, $statuses);

            return;
        }

        $accessToken = $this->accessToken($run);
        $currentCart = $silpo->getReadyCart($accessToken, $run->harnessRun);

        if ($currentCart === null
            || $currentCart->cartId !== $run->cart_id
            || $currentCart->fingerprint() !== $run->delivery_fingerprint) {
            $this->markStale($run, $statuses);

            return;
        }

        [$products, $targets, $warnings, $hasObsoleteManagedItems] = $this->absoluteProducts($run, $currentCart);

        if ($products === []) {
            $this->finish($run, $currentCart, CartRunStatus::Partial, [
                ...$warnings,
                'Гусь не знайшов жодного товару для запису.',
            ], $statuses);

            return;
        }

        $this->markCommitting($run, $statuses);

        try {
            $silpo->addOrUpdateProducts($accessToken, $run->cart_id, $products, $run->harnessRun);
            $verifiedCart = $silpo->getReadyCart($accessToken, $run->harnessRun);
        } catch (Throwable $throwable) {
            $verifiedCart = $silpo->getReadyCart($accessToken, $run->harnessRun);

            if ($verifiedCart === null || ! $this->targetsMatch($verifiedCart, $targets)) {
                throw $throwable;
            }
        }

        if ($verifiedCart === null || $verifiedCart->fingerprint() !== $run->delivery_fingerprint) {
            throw new UnexpectedValueException('Silpo cart context changed during verification.');
        }

        $missingTargets = $this->missingTargets($verifiedCart, $targets);
        $managedProductIds = array_keys($targets);
        $managedValidations = collect($verifiedCart->validations)
            ->reject(fn (array $validation): bool => in_array(
                data_get($validation, 'message'),
                ['promotion.available', 'order.cost.min'],
                true,
            )
                || mb_strtolower((string) data_get($validation, 'level')) === 'info'
                || mb_strtolower((string) data_get($validation, 'type')) === 'info')
            ->filter(fn (array $validation): bool => in_array(data_get($validation, 'product_id'), $managedProductIds, true))
            ->values()
            ->all();
        $validationWarnings = collect($verifiedCart->validations)
            ->reject(fn (array $validation): bool => in_array(
                data_get($validation, 'message'),
                ['promotion.available', 'order.cost.min'],
                true,
            )
                || mb_strtolower((string) data_get($validation, 'level')) === 'info'
                || mb_strtolower((string) data_get($validation, 'type')) === 'info')
            ->map(fn (array $validation): string => $this->validationMessage((string) data_get($validation, 'message')))
            ->unique()
            ->values()
            ->all();
        $warnings = array_values(array_unique([
            ...$warnings,
            ...$validationWarnings,
            ...collect($missingTargets)->map(fn (string $name): string => "Не вдалося підтвердити кількість: {$name}.")->all(),
        ]));
        $hasCoverageGap = (bool) data_get($run->state, 'has_unmet_needs', false)
            || $hasObsoleteManagedItems
            || $missingTargets !== []
            || $managedValidations !== []
            || collect($warnings)->contains(
                fn (string $warning): bool => str_starts_with($warning, 'У Сільпо не вистачило повної кількості:'),
            );

        $this->finish(
            $run,
            $verifiedCart,
            $hasCoverageGap ? CartRunStatus::Partial : CartRunStatus::Synced,
            $warnings,
            $statuses,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $run = EventCartRun::query()->with('event')->find($this->runId);

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        $message = 'Гусь не зміг безпечно підтвердити запис у кошик. Нічого повторно навмання не додавав.';
        $run->update([
            'status' => CartRunStatus::Failed,
            'phase' => CartRunPhase::Finished,
            'error' => $message,
            'finished_at' => now(),
        ]);
        app(GooseCartStatusService::class)->append($run, 'warning');
        $run->event->update([
            'cart_sync_status' => CartSyncStatus::Failed,
            'cart_sync_error' => $message,
        ]);
    }

    private function markCommitting(EventCartRun $run, GooseCartStatusService $statuses): void
    {
        DB::transaction(function () use ($run, $statuses): void {
            $lockedRun = EventCartRun::query()->lockForUpdate()->findOrFail($run->id);

            if (! $this->canCommit($lockedRun)) {
                return;
            }

            $lockedRun->update([
                'status' => CartRunStatus::Committing,
                'phase' => CartRunPhase::Committing,
                'cursor' => $lockedRun->cursor + 1,
            ]);
            $statuses->append($lockedRun, 'committing');
        });

        $run->refresh();
    }

    /**
     * @return array{array<int, array<string, mixed>>, array<string, float>, array<int, string>, bool}
     */
    private function absoluteProducts(EventCartRun $run, SilpoCartContextData $currentCart): array
    {
        $stagedByProduct = collect($run->staged_items ?? [])->groupBy('product_id');
        $previousRun = $run->event->cartRuns()
            ->where('id', '!=', $run->id)
            ->whereIn('status', [CartRunStatus::Synced->value, CartRunStatus::Partial->value])
            ->latest()
            ->first();
        $previousByProduct = collect($previousRun?->staged_items ?? [])->groupBy('product_id');
        $currentByProduct = collect($currentCart->items)->keyBy('product_id');
        $warnings = [];
        $targets = [];
        $products = [];

        foreach ($stagedByProduct as $productId => $items) {
            $item = $items->first();
            $newManagedQuantity = (float) $items->sum('quantity');
            $previousManagedQuantity = (float) ($previousByProduct->get($productId)?->sum('quantity') ?? 0);
            $currentQuantity = (float) data_get($currentByProduct->get($productId), 'quantity', 0);
            $target = max(0, $currentQuantity - $previousManagedQuantity) + $newManagedQuantity;
            $step = max((float) data_get($item, 'step', 1), 0.001);
            $stock = (float) data_get($item, 'stock', $target);
            $target = round(ceil(($target - 0.0000001) / $step) * $step, 3);

            if ($stock > 0 && $target > $stock) {
                $target = $stock;
                $warnings[] = 'У Сільпо не вистачило повної кількості: '.data_get($item, 'name').'.';
            }

            $targets[(string) $productId] = $target;
            $products[] = [
                'productId' => (string) $productId,
                'companyId' => (string) data_get($item, 'company_id'),
                'branchId' => (string) data_get($item, 'branch_id'),
                'quantity' => $target,
                'addQuantity' => false,
            ];
        }

        $obsoleteManagedItems = $previousByProduct->keys()->diff($stagedByProduct->keys());

        if ($obsoleteManagedItems->isNotEmpty()) {
            $warnings[] = 'Попередні товари цієї події, яких уже немає в новому списку, Гусь не видаляв автоматично.';
        }

        return [$products, $targets, $warnings, $obsoleteManagedItems->isNotEmpty()];
    }

    /** @param array<string, float> $targets */
    private function targetsMatch(SilpoCartContextData $cart, array $targets): bool
    {
        return $this->missingTargets($cart, $targets) === [];
    }

    /**
     * @param  array<string, float>  $targets
     * @return array<int, string>
     */
    private function missingTargets(SilpoCartContextData $cart, array $targets): array
    {
        $actualItems = collect($cart->items)->keyBy('product_id');
        $missing = [];

        foreach ($targets as $productId => $quantity) {
            $actual = $actualItems->get($productId);

            if (! is_array($actual) || abs((float) data_get($actual, 'quantity') - $quantity) > 0.001) {
                $missing[] = is_array($actual) ? (string) data_get($actual, 'name', $productId) : $productId;
            }
        }

        return $missing;
    }

    /** @param array<int, string> $warnings */
    private function finish(
        EventCartRun $run,
        SilpoCartContextData $cart,
        CartRunStatus $status,
        array $warnings,
        GooseCartStatusService $statuses,
    ): void {
        DB::transaction(function () use ($run, $cart, $status, $warnings, $statuses): void {
            $lockedRun = EventCartRun::query()->lockForUpdate()->findOrFail($run->id);
            $state = $lockedRun->state;
            $state['verified_cart'] = $cart->toRunContext();
            $lockedRun->update([
                'status' => $status,
                'phase' => CartRunPhase::Finished,
                'state' => $state,
                'warnings' => $warnings,
                'actual_total' => $cart->totalAfterDiscounts,
                'finished_at' => now(),
                'cursor' => $lockedRun->cursor + 1,
            ]);
            $statuses->append($lockedRun, 'verifying');
            $statuses->append($lockedRun, $status === CartRunStatus::Synced ? 'success' : 'warning');
            $lockedRun->event()->update([
                'silpo_cart_id' => $cart->cartId,
                'cart_sync_status' => $status === CartRunStatus::Synced
                    ? CartSyncStatus::Synced
                    : CartSyncStatus::Partial,
                'cart_synced_state_version' => $status === CartRunStatus::Synced
                    ? $lockedRun->plan_state_version
                    : null,
                'cart_synced_at' => now(),
                'cart_sync_error' => $status === CartRunStatus::Synced ? null : implode(' ', $warnings),
            ]);
        });
    }

    private function markStale(EventCartRun $run, GooseCartStatusService $statuses): void
    {
        $message = 'Список або маршрут Сільпо змінився. Гусь нічого не додавав — запустіть його ще раз.';
        $run->update([
            'status' => CartRunStatus::Stale,
            'phase' => CartRunPhase::Finished,
            'error' => $message,
            'finished_at' => now(),
        ]);
        $statuses->append($run, 'warning');
        $run->event()->update([
            'cart_sync_status' => CartSyncStatus::Stale,
            'cart_sync_error' => $message,
        ]);
    }

    private function validationMessage(string $message): string
    {
        return match ($message) {
            'product.offer.stock.max' => 'Один із товарів перевищує доступний залишок Сільпо.',
            'order.payment_types.disabled' => 'Для поточного кошика Сільпо ще не дозволило спосіб оплати.',
            default => 'Сільпо просить додатково перевірити кошик.',
        };
    }

    private function canCommit(?EventCartRun $run): bool
    {
        return $run !== null
            && $run->status === CartRunStatus::Committing
            && $run->phase === CartRunPhase::ReadyToCommit
            && $run->cursor === $this->expectedCursor;
    }

    private function accessToken(EventCartRun $run): string
    {
        $connection = $run->event->user->silpoConnection;

        if ($connection === null || $connection->revoked_at !== null
            || ($connection->expires_at !== null && $connection->expires_at->isPast())) {
            throw new UnexpectedValueException('Silpo connection is not available for the cart run.');
        }

        return $connection->access_token;
    }
}
