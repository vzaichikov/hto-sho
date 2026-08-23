<?php

namespace App\Models;

use App\CartRunMode;
use App\CartRunPhase;
use App\CartRunStatus;
use Database\Factories\EventCartRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'event_id',
    'harness_run_id',
    'mode',
    'status',
    'phase',
    'plan_state_version',
    'cursor',
    'cart_id',
    'delivery_fingerprint',
    'cart_context',
    'state',
    'staged_items',
    'warnings',
    'blocker',
    'error',
    'estimated_total',
    'actual_total',
    'started_at',
    'finished_at',
])]
class EventCartRun extends Model
{
    /** @use HasFactory<EventCartRunFactory> */
    use HasFactory;

    protected $attributes = [
        'mode' => 'assisted',
        'status' => 'running',
        'phase' => 'preparing',
        'cursor' => 0,
        'state' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'mode' => CartRunMode::class,
            'status' => CartRunStatus::class,
            'phase' => CartRunPhase::class,
            'plan_state_version' => 'integer',
            'cursor' => 'integer',
            'cart_context' => 'array',
            'state' => 'array',
            'staged_items' => 'array',
            'warnings' => 'array',
            'estimated_total' => 'decimal:2',
            'actual_total' => 'decimal:2',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function harnessRun(): BelongsTo
    {
        return $this->belongsTo(HarnessRun::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(EventCartRunStep::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}
