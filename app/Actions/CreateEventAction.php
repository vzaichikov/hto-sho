<?php

namespace App\Actions;

use App\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateEventAction
{
    public function __construct(private readonly StartEventAnalysisAction $startAnalysis) {}

    /**
     * @param  array{title: string, description: string, budget_amount?: int|float|string|null, alcohol_planned: bool}  $attributes
     */
    public function execute(User $user, array $attributes): Event
    {
        $event = DB::transaction(fn (): Event => $user->events()->create([
            ...$attributes,
            'status' => EventStatus::Draft,
            'evidence_version' => 1,
        ]));

        return $this->startAnalysis->execute($event);
    }
}
