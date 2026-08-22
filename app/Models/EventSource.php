<?php

namespace App\Models;

use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use Database\Factories\EventSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id',
    'image_extraction_id',
    'type',
    'origin',
    'metadata',
    'text',
    'file_path',
    'original_name',
    'mime_type',
    'size',
    'upload_batch',
    'position',
    'content_hash',
    'status',
    'inclusion',
    'used_cached_extraction',
    'processing_error',
    'processed_at',
])]
class EventSource extends Model
{
    /** @use HasFactory<EventSourceFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
        'inclusion' => 'included',
        'origin' => 'organizer_context',
    ];

    protected function casts(): array
    {
        return [
            'type' => EventSourceType::class,
            'status' => EventSourceStatus::class,
            'inclusion' => EventSourceInclusion::class,
            'used_cached_extraction' => 'boolean',
            'metadata' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function imageExtraction(): BelongsTo
    {
        return $this->belongsTo(ImageExtraction::class);
    }

    public function isIncluded(): bool
    {
        return $this->inclusion->isIncluded();
    }
}
