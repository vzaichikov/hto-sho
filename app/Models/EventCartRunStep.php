<?php

namespace App\Models;

use Database\Factories\EventCartRunStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['event_cart_run_id', 'sequence', 'kind', 'message', 'context'])]
class EventCartRunStep extends Model
{
    /** @use HasFactory<EventCartRunStepFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'context' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EventCartRun::class, 'event_cart_run_id');
    }
}
