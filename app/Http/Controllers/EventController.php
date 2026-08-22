<?php

namespace App\Http\Controllers;

use App\Actions\DeleteEventAction;
use App\EventStatus;
use App\Http\Requests\UpdateEventRequest;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EventController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Event::class);

        $events = $request->user()
            ->events()
            ->withCount('sources')
            ->latest('updated_at')
            ->paginate(12);

        return view('events.index', ['events' => $events]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Event::class);

        $event = $request->user()->events()->create([
            'title' => 'Подія · '.now()->format('d.m H:i'),
            'status' => EventStatus::Draft,
        ]);

        return redirect()->route('events.show', $event);
    }

    public function show(Event $event): View
    {
        Gate::authorize('view', $event);

        $event->load([
            'sources' => fn ($query) => $query
                ->with('imageExtraction')
                ->oldest('created_at')
                ->oldest('id'),
        ]);

        return view('events.show', ['event' => $event]);
    }

    public function update(UpdateEventRequest $request, Event $event): RedirectResponse
    {
        $event->update($request->validated());

        return back()->with('success', 'Назву події оновлено.');
    }

    public function destroy(Event $event, DeleteEventAction $deleteEvent): RedirectResponse
    {
        Gate::authorize('delete', $event);

        $deleteEvent->execute($event);

        return redirect()->route('events.index')->with('success', 'Подію та її джерела видалено.');
    }
}
