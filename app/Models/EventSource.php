<?php

namespace App\Models;

use App\EventSourceStatus;
use App\EventSourceType;
use Database\Factories\EventSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id',
    'type',
    'text',
    'file_path',
    'original_name',
    'mime_type',
    'size',
    'upload_batch',
    'position',
    'content_hash',
    'status',
    'processing_error',
    'processed_at',
])]
class EventSource extends Model
{
    /** @use HasFactory<EventSourceFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'type' => EventSourceType::class,
            'status' => EventSourceStatus::class,
            'processed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
