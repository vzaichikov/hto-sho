<?php

namespace App\Http\Controllers;

use App\Actions\ResetSilpoCartAction;
use App\Exceptions\SilpoCartUnavailableException;
use App\Http\Requests\ResetSilpoCartRequest;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

class ResetSilpoCartController extends Controller
{
    public function __invoke(
        ResetSilpoCartRequest $request,
        Event $event,
        ResetSilpoCartAction $reset,
    ): JsonResponse {
        Gate::authorize('update', $event);

        try {
            return response()->json($reset->execute(
                $request->user(),
                $event,
                $request->validated('reset_token'),
            ));
        } catch (SilpoCartUnavailableException $exception) {
            return response()->json([
                'ready' => false,
                'code' => $exception->reason,
                'message' => $exception->getMessage(),
                'action_url' => $exception->reason === 'connection_missing'
                    ? route('mcp.oauth.silpo.connect')
                    : config('services.silpo_mcp.shop_url'),
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
                'message' => 'Сільпо не підтвердило очищення. Копія кошика збережена; Гусь зупинився.',
            ], 503);
        }
    }
}
