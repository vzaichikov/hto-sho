<?php

namespace App\Http\Controllers;

use App\Actions\DiscoverSilpoFulfilmentAction;
use App\Http\Requests\DiscoverSilpoFulfilmentRequest;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

class DiscoverSilpoFulfilmentController extends Controller
{
    public function __invoke(
        DiscoverSilpoFulfilmentRequest $request,
        Event $event,
        DiscoverSilpoFulfilmentAction $discover,
    ): JsonResponse {
        try {
            return response()->json($discover->execute(
                $request->user(),
                $event,
                $request->validated(),
            ));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'message' => 'Сільпо сховало цей маршрут від Гуся. Спробуйте ще раз за хвилину.',
            ], 503);
        }
    }
}
