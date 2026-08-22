<?php

namespace App\Http\Controllers;

use App\Actions\StoreEventPlanCorrectionAction;
use App\Http\Requests\StoreEventPlanCorrectionRequest;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;

class EventPlanCorrectionController extends Controller
{
    public function __invoke(
        StoreEventPlanCorrectionRequest $request,
        Event $event,
        StoreEventPlanCorrectionAction $storeCorrection,
    ): RedirectResponse {
        $validated = $request->validated();
        $created = $storeCorrection->execute(
            $event,
            $validated['plan_state_version'],
            $validated['correction'],
        );

        return redirect()
            ->route('events.show', ['event' => $event, 'tab' => 'plan'])
            ->with(
                $created > 0 ? 'success' : 'info',
                $created > 0
                    ? 'Гусь почув корективу й уже перебудовує список.'
                    : 'Цю корективу Гусь уже почув.',
            );
    }
}
