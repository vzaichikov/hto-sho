<?php

use App\Http\Controllers\EventAnalysisController;
use App\Http\Controllers\EventCartController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventSourceController;
use App\Http\Controllers\EventSourceInclusionController;
use App\Http\Controllers\EventStatusController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\RetryEventSourceController;
use App\Http\Controllers\SilpoOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('landing');

Route::get('/login/silpo', [SilpoOAuthController::class, 'redirect'])
    ->middleware('throttle:silpo-oauth')
    ->name('mcp.oauth.silpo.connect');
Route::get('/mcp/oauth/silpo/callback', [SilpoOAuthController::class, 'callback'])
    ->middleware('throttle:silpo-oauth')
    ->name('mcp.oauth.silpo.callback');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', LogoutController::class)->name('logout');

    Route::resource('events', EventController::class)->only([
        'index',
        'store',
        'show',
        'update',
        'destroy',
    ]);

    Route::scopeBindings()->group(function (): void {
        Route::post('/events/{event}/sources', [EventSourceController::class, 'store'])
            ->name('events.sources.store');
        Route::get('/events/{event}/sources/{source}', [EventSourceController::class, 'show'])
            ->name('events.sources.show');
        Route::delete('/events/{event}/sources/{source}', [EventSourceController::class, 'destroy'])
            ->name('events.sources.destroy');
        Route::patch('/events/{event}/sources/{source}/inclusion', EventSourceInclusionController::class)
            ->name('events.sources.inclusion');
        Route::post('/events/{event}/sources/{source}/retry', RetryEventSourceController::class)
            ->name('events.sources.retry');
    });
    Route::post('/events/{event}/analysis', EventAnalysisController::class)
        ->name('events.analysis.store');
    Route::get('/events/{event}/status', EventStatusController::class)
        ->name('events.status');
    Route::post('/events/{event}/cart-sync', EventCartController::class)
        ->name('events.cart-sync');
});
