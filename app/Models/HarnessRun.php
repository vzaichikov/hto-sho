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

    public function scopeVisibleInJournalFor(Builder $query, Event $event): void
    {
        $imageCorrelationIds = $event->sources()
            ->whereNotNull('image_extraction_id')
            ->pluck('image_extraction_id')
            ->map(fn (int $imageExtractionId): string => 'image-'.$imageExtractionId)
            ->all();

        $query->where(function (Builder $visibleQuery) use ($event, $imageCorrelationIds): void {
            $visibleQuery->whereBelongsTo($event);

            if ($imageCorrelationIds !== []) {
                $visibleQuery->orWhere(function (Builder $imageQuery) use ($imageCorrelationIds): void {
                    $imageQuery
                        ->where('type', HarnessRunType::ImageExtraction)
                        ->whereIn('correlation_id', $imageCorrelationIds);
                });
            }
        });
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
