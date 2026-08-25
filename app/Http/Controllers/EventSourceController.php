<?php

namespace App\Http\Controllers;

use App\Actions\DeleteEventSourceAction;
use App\Actions\StoreEventSourcesAction;
use App\EventSourceType;
use App\Http\Requests\StoreEventSourcesRequest;
use App\Models\Event;
use App\Models\EventSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventSourceController extends Controller
{
    public function store(
        StoreEventSourcesRequest $request,
        Event $event,
        StoreEventSourcesAction $storeSources,
    ): JsonResponse|RedirectResponse {
        $created = $storeSources->execute(
            $event,
            $request->validated('text'),
            $request->file('images', []),
        );
        $redirect = route('events.show', ['event' => $event, 'tab' => 'context']);
        $message = $created === 0
            ? 'Ці матеріали Гусь уже бачив.'
            : 'Гусь усе отримав і вже оновлює план.';

        if ($request->header('X-Share-Target') === '1') {
            $request->session()->forget([
                'share_target.pending',
                'share_target.return_after_auth',
                'share_target.return_after_create',
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'created' => $created,
                'message' => $message,
                'redirect' => $redirect,
            ], $created === 0 ? 200 : 201);
        }

        if ($created === 0) {
            return redirect()
                ->to($redirect)
                ->with('info', $message);
        }

        return redirect()
            ->to($redirect)
            ->with('success', $message);
    }

    public function show(Event $event, EventSource $source): StreamedResponse
    {
        Gate::authorize('view', $event);

        abort_unless($source->event_id === $event->id, 404);
        abort_unless($source->type === EventSourceType::Image && $source->file_path !== null, 404);
        abort_unless(Storage::disk('local')->exists($source->file_path), 404);

        return Storage::disk('local')->response(
            $source->file_path,
            $source->original_name,
            ['Content-Disposition' => 'inline'],
        );
    }

    public function destroy(
        Event $event,
        EventSource $source,
        DeleteEventSourceAction $deleteSource,
    ): RedirectResponse {
        Gate::authorize('update', $event);
        $deleteSource->execute($event, $source);

        return redirect()
            ->route('events.show', ['event' => $event, 'tab' => 'context'])
            ->with('success', 'Матеріал прибрано. Гусь уже перераховує.');
    }
}
