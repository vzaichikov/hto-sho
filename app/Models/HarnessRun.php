<?php

namespace App\Models;

use App\HarnessRunStatus;
use App\HarnessRunType;
use Database\Factories\HarnessRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'event_id',
    'type',
    'status',
    'correlation_id',
    'metadata',
    'next_sequence',
    'error',
    'started_at',
    'finished_at',
])]
class HarnessRun extends Model
{
    /** @use HasFactory<HarnessRunFactory> */
    use HasFactory, MassPrunable;

    protected $attributes = [
        'status' => 'running',
        'next_sequence' => 1,
    ];

    protected function casts(): array
    {
        return [
            'type' => HarnessRunType::class,
            'status' => HarnessRunStatus::class,
            'metadata' => 'array',
            'next_sequence' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(HarnessEntry::class);
    }

    public function cartRun(): HasOne
    {
        return $this->hasOne(EventCartRun::class);
    }

    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<=', now()->subDays(90));
    }
}
