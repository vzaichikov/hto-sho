<?php

namespace App\Http\Controllers;

use App\Actions\StoreEventAnswersAction;
use App\Http\Requests\StoreEventAnswersRequest;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;

class EventAnswerController extends Controller
{
    public function __invoke(
        StoreEventAnswersRequest $request,
        Event $event,
        StoreEventAnswersAction $storeAnswers,
    ): RedirectResponse {
        $validated = $request->validated();
        $created = $storeAnswers->execute($event, $validated['state_version'], $validated['answers']);

        return redirect()
            ->route('events.show', ['event' => $event, 'tab' => 'questions'])
            ->with(
                $created > 0 ? 'success' : 'info',
                $created > 0
                    ? 'Гусь почув відповіді й уже оновлює план.'
                    : 'Ці відповіді Гусь уже почув.',
            );
    }
}
