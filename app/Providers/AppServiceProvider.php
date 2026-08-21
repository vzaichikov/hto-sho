<?php

namespace App\Providers;

use App\Contracts\SilpoProfileGateway;
use App\Services\McpSilpoProfileGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SilpoProfileGateway::class, McpSilpoProfileGateway::class);
    }

    public function boot(): void
    {
        RateLimiter::for('silpo-oauth', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
