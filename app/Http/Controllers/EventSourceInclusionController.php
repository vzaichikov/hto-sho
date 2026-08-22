<?php

namespace App\Http\Controllers;

use App\Actions\ChangeEventSourceInclusionAction;
use App\EventSourceInclusion;
use App\Http\Requests\UpdateEventSourceInclusionRequest;
use App\Models\Event;
use App\Models\EventSource;
use Illuminate\Http\RedirectResponse;

class EventSourceInclusionController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        UpdateEventSourceInclusionRequest $request,
        Event $event,
        EventSource $source,
        ChangeEventSourceInclusionAction $changeInclusion,
    ): RedirectResponse {
        $inclusion = EventSourceInclusion::from($request->validated('inclusion'));
        $changeInclusion->execute($event, $source, $inclusion);

        return back()->with(
            'success',
            $inclusion === EventSourceInclusion::Forced
                ? 'Гаразд, Гусь бере цей матеріал до уваги й оновлює план.'
                : 'Гусь відклав цей матеріал убік і оновлює план.',
        );
    }
}
