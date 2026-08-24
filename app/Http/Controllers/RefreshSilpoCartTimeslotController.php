<?php

namespace App\Http\Controllers;

use App\Actions\RefreshSilpoCartTimeslotAction;
use App\Http\Requests\RefreshSilpoCartTimeslotRequest;
use App\Models\Event;
use App\Services\SilpoCartValidationPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

class RefreshSilpoCartTimeslotController extends Controller
{
    public function __invoke(
        RefreshSilpoCartTimeslotRequest $request,
        Event $event,
        RefreshSilpoCartTimeslotAction $refresh,
        SilpoCartValidationPresenter $validationPresenter,
    ): JsonResponse {
        try {
            $cart = $refresh->execute($request->user(), $event, $request->validated());
        } catch (LockTimeoutException|RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'message' => 'Сільпо не підтвердило новий час. Гусь нічого не змінюватиме навмання — перевірте кошик ще раз.',
            ], 503);
        }

        if ($cart === null) {
            return response()->json([
                'message' => 'Кошик або доступний час уже змінилися. Гусь нічого не перезаписав — перевірте ще раз.',
                'code' => 'cart_changed',
            ], 409);
        }

        return response()->json([
            'ready' => true,
            'message' => 'Час оновлено. Маршрут лишився тим самим.',
            'cart' => [
                'delivery_label' => $cart->deliveryLabel(),
                'timeslot' => $this->timeslotLabel($cart->slotStart, $cart->slotEnd),
                'items' => $cart->items,
                'items_count' => count($cart->items),
                'total' => $cart->totalAfterDiscounts,
                'delivery_cost' => data_get($cart->slot, 'deliveryCost'),
                'min_order_cost' => data_get($cart->slot, 'minOrderCost'),
                'validations' => $validationPresenter->present($cart->validations),
            ],
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
