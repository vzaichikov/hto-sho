<?php

namespace App\Http\Controllers;

use App\CartRunStatus;
use App\Contracts\SilpoCartGateway;
use App\HarnessRunType;
use App\Models\Event;
use App\Services\HarnessRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

class SilpoCartPreflightController extends Controller
{
    public function __invoke(
        Request $request,
        Event $event,
        SilpoCartGateway $silpo,
        HarnessRecorder $harnessRecorder,
    ): JsonResponse {
        Gate::authorize('view', $event);

        $activeRun = $event->cartRuns()
            ->whereIn('status', [
                CartRunStatus::Running->value,
                CartRunStatus::WaitingForAnswer->value,
                CartRunStatus::WaitingForConfirmation->value,
                CartRunStatus::Committing->value,
            ])
            ->latest()
            ->first();

        if ($activeRun !== null) {
            return response()->json([
                'ready' => true,
                'active_run_url' => route('events.cart-runs.show', [$event, $activeRun]),
            ]);
        }

        $connection = $request->user()?->silpoConnection()
            ->whereNull('revoked_at')
            ->first();

        if ($connection === null || ($connection->expires_at !== null && $connection->expires_at->isPast())) {
            return response()->json([
                'ready' => false,
                'code' => 'connection_missing',
                'message' => 'Гусь загубив звʼязок із Сільпо. Підключіть його ще раз.',
                'action_url' => route('mcp.oauth.silpo.connect'),
                'action_label' => 'Підключити Сільпо',
            ], 409);
        }

        $harnessRun = $harnessRecorder->start(
            event: $event,
            type: HarnessRunType::SilpoPreflight,
            correlationId: (string) Str::ulid(),
        );

        try {
            $cart = $silpo->getReadyCart($connection->access_token, $harnessRun);
            $refreshCandidate = $cart === null
                ? $silpo->getCartRefreshCandidate($connection->access_token, $harnessRun)
                : null;
            $harnessRecorder->finish($harnessRun);
        } catch (Throwable $throwable) {
            $harnessRecorder->fail($harnessRun, $throwable);
            report($throwable);

            return response()->json([
                'ready' => false,
                'code' => 'silpo_unavailable',
                'message' => 'Сільпо зараз не відчиняє двері Гусю. Спробуйте ще раз за хвилину.',
            ], 503);
        }

        if ($cart === null) {
            if ($refreshCandidate !== null) {
                return response()->json([
                    'ready' => false,
                    'code' => 'timeslot_expired',
                    'message' => 'Маршрут на місці, але час уже протух. Гусь може переставити його на найближчий доступний — після вашого підтвердження.',
                    'refresh_url' => route('events.silpo.cart-refresh', $event),
                    'candidate' => [
                        'delivery_label' => $refreshCandidate->deliveryLabel(),
                        'current_timeslot' => $this->timeslotLabel(
                            $refreshCandidate->currentSlotStart,
                            $refreshCandidate->currentSlotEnd,
                        ),
                        'timeslot' => $this->timeslotLabel(
                            $refreshCandidate->candidateSlotStart,
                            $refreshCandidate->candidateSlotEnd,
                        ),
                        'slot_start' => $refreshCandidate->candidateSlotStart,
                        'slot_end' => $refreshCandidate->candidateSlotEnd,
                        'route_fingerprint' => $refreshCandidate->routeFingerprint,
                        'current_slot_fingerprint' => $refreshCandidate->currentSlotFingerprint,
                    ],
                ], 409);
            }

            return response()->json([
                'ready' => false,
                'code' => 'cart_missing',
                'message' => 'Гусь без маршруту загубиться. Зайдіть у Сільпо, створіть кошик та оберіть адресу доставки і спосіб отримання.',
                'action_url' => config('services.silpo_mcp.shop_url'),
                'action_label' => 'Відкрити Сільпо',
            ], 409);
        }

        return response()->json([
            'ready' => true,
            'cart' => [
                'delivery_label' => $cart->deliveryLabel(),
                'timeslot' => $this->timeslotLabel($cart->slotStart, $cart->slotEnd),
                'items' => $cart->items,
                'items_count' => count($cart->items),
                'total' => $cart->totalAfterDiscounts,
                'delivery_cost' => data_get($cart->slot, 'deliveryCost'),
                'min_order_cost' => data_get($cart->slot, 'minOrderCost'),
                'validations' => $cart->validations,
            ],
            'start_url' => route('events.cart-runs.store', $event),
        ]);
    }

    private function timeslotLabel(string $start, string $end): string
    {
        $timezone = (string) config('app.timezone');
        $startAt = CarbonImmutable::parse($start)->setTimezone($timezone);
        $endAt = CarbonImmutable::parse($end)->setTimezone($timezone);

        return $startAt->translatedFormat('j M, H:i').'–'.$endAt->format('H:i');
    }
}
