<?php

namespace App\Http\Controllers;

use App\Actions\RetryEventSourceAction;
use App\Models\Event;
use App\Models\EventSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class RetryEventSourceController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        Event $event,
        EventSource $source,
        RetryEventSourceAction $retrySource,
    ): RedirectResponse {
        Gate::authorize('update', $event);
        $retrySource->execute($event, $source);

        return back()->with('success', 'Ще одна спроба. Цього разу Гусь примружився сильніше.');
    }
}
