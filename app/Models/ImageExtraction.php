<?php

namespace App\Models;

use App\ImageClassification;
use App\ImageExtractionStatus;
use Database\Factories\ImageExtractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'content_hash',
    'status',
    'classification',
    'ocr_text',
    'message_timeline',
    'source_summary',
    'dismissal_reason',
    'processing_error',
    'processing_started_at',
    'processed_at',
])]
class ImageExtraction extends Model
{
    /** @use HasFactory<ImageExtractionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImageExtractionStatus::class,
            'classification' => ImageClassification::class,
            'message_timeline' => 'array',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(EventSource::class);
    }
}
