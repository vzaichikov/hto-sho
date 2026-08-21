<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EventCartController extends Controller
{
    public function __invoke(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize('update', $event);

        if (! $event->isPlanCurrent()) {
            return back()->with('error', 'Спочатку дочекайтеся актуального списку покупок.');
        }

        return back()->with('info', 'Запис товарів у кошик буде підключено разом із harness підбору.');
    }
}
