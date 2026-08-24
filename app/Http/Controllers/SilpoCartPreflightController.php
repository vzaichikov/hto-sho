<?php

namespace App\Http\Controllers;

use App\Actions\PrepareSilpoFulfilmentAction;
use App\Exceptions\SilpoCartUnavailableException;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

class SilpoCartPreflightController extends Controller
{
    public function __invoke(
        Request $request,
        Event $event,
        PrepareSilpoFulfilmentAction $prepare,
    ): JsonResponse {
        Gate::authorize('update', $event);

        try {
            return response()->json($prepare->execute($request->user(), $event));
        } catch (SilpoCartUnavailableException $exception) {
            return response()->json([
                'ready' => false,
                'code' => $exception->reason,
                'message' => $exception->getMessage(),
                'action_url' => $exception->reason === 'connection_missing'
                    ? route('mcp.oauth.silpo.connect')
                    : config('services.silpo_mcp.shop_url'),
                'action_label' => $exception->reason === 'connection_missing'
                    ? 'Повернути Гусю Сільпо'
                    : 'Показати Гусю кошик',
            ], 409);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ready' => false,
                'message' => $exception->getMessage(),
            ], 409);
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'ready' => false,
                'code' => 'silpo_unavailable',
                'message' => 'Сільпо зараз не відчиняє двері Гусю. Спробуйте ще раз за хвилину.',
            ], 503);
        }
    }
}
