<?php

namespace App\Models;

use App\HarnessEntryKind;
use Database\Factories\HarnessEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'harness_run_id',
    'sequence',
    'kind',
    'status',
    'title',
    'message',
    'method',
    'endpoint',
    'status_code',
    'duration_ms',
    'request_payload',
    'response_payload',
    'metadata',
])]
class HarnessEntry extends Model
{
    /** @use HasFactory<HarnessEntryFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'completed',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'kind' => HarnessEntryKind::class,
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'metadata' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(HarnessRun::class, 'harness_run_id');
    }
}
