<?php

namespace App\Providers;

use App\Contracts\AgenticSilpoCartRunner;
use App\Contracts\CartProductAgent;
use App\Contracts\SilpoCartGateway;
use App\Contracts\SilpoProfileGateway;
use App\Contracts\SilpoRouteIntentInterpreter;
use App\Services\AiSilpoRouteIntentInterpreter;
use App\Services\CartProductDecisionService;
use App\Services\GooseCartStatusService;
use App\Services\HarnessRecorder;
use App\Services\McpSilpoCartGateway;
use App\Services\McpSilpoProfileGateway;
use App\Services\OpenAiSilpoCartRunner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SilpoProfileGateway::class, McpSilpoProfileGateway::class);
        $this->app->bind(SilpoCartGateway::class, McpSilpoCartGateway::class);
        $this->app->bind(CartProductAgent::class, CartProductDecisionService::class);
        $this->app->bind(AgenticSilpoCartRunner::class, OpenAiSilpoCartRunner::class);
        $this->app->bind(SilpoRouteIntentInterpreter::class, AiSilpoRouteIntentInterpreter::class);
        $this->app->bind(
            GooseCartStatusService::class,
            fn ($app): GooseCartStatusService => new GooseCartStatusService($app->make(HarnessRecorder::class)),
        );
    }

    public function boot(): void
    {
        RateLimiter::for('silpo-oauth', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('event-description-review', function (Request $request): Limit {
            $message = 'Гусь не встигає так швидко клювати нові задуми. Перепочиньте хвилинку й повторіть.';

            return Limit::perMinute(10)
                ->by('user:'.($request->user()?->id ?? $request->ip()))
                ->response(function (Request $request, array $headers) use ($message) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => $message], 429, $headers);
                    }

                    return response()->view('events.create', [
                        'failureMessage' => $message,
                        'form' => $request->only(['title', 'description']),
                        'initialStep' => 2,
                    ], 429, $headers);
                });
        });

        RateLimiter::for('cart-runs', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('user:'.($request->user()?->id ?? $request->ip())));
    }
}
