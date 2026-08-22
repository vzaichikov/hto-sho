<?php

namespace App\Models;

use Database\Factories\EventContextVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id',
    'state_version',
    'evidence_version',
    'state',
])]
class EventContextVersion extends Model
{
    /** @use HasFactory<EventContextVersionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'state_version' => 'integer',
            'evidence_version' => 'integer',
            'state' => 'array',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
