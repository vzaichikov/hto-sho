<?php

namespace App\Models;

use App\CartSyncStatus;
use App\EventAnalysisStage;
use App\EventStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'title',
    'description',
    'people_count',
    'status',
    'state',
    'state_version',
    'evidence_version',
    'state_evidence_version',
    'shopping_plan',
    'plan_state_version',
    'budget_amount',
    'estimated_total',
    'currency',
    'analysis_error',
    'analysis_task_id',
    'analysis_stage',
    'analysis_started_at',
    'analysis_finished_at',
    'silpo_cart_id',
    'cart_sync_status',
    'cart_synced_state_version',
    'cart_synced_at',
    'cart_sync_error',
    'last_source_at',
])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected $attributes = [
        'title' => 'Нова подія',
        'status' => 'draft',
        'state_version' => 0,
        'evidence_version' => 0,
        'currency' => 'UAH',
        'cart_sync_status' => 'not_synced',
    ];

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'state' => 'array',
            'state_version' => 'integer',
            'evidence_version' => 'integer',
            'state_evidence_version' => 'integer',
            'shopping_plan' => 'array',
            'people_count' => 'integer',
            'budget_amount' => 'decimal:2',
            'estimated_total' => 'decimal:2',
            'cart_sync_status' => CartSyncStatus::class,
            'cart_synced_at' => 'datetime',
            'last_source_at' => 'datetime',
            'analysis_stage' => EventAnalysisStage::class,
            'analysis_started_at' => 'datetime',
            'analysis_finished_at' => 'datetime',
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

    public function isPlanCurrent(): bool
    {
        return $this->status === EventStatus::Ready
            && ! $this->hasUnanalyzedChanges()
            && $this->shopping_plan !== null
            && $this->plan_state_version === $this->state_version;
    }

    public function hasUnanalyzedChanges(): bool
    {
        return $this->state === null
            || $this->state_evidence_version !== $this->evidence_version;
    }

    public function hasActiveAnalysis(): bool
    {
        return $this->analysis_task_id !== null
            && in_array($this->analysis_stage, [
                EventAnalysisStage::WaitingForQuiet,
                EventAnalysisStage::WaitingForImages,
                EventAnalysisStage::Summarizing,
            ], true);
    }

    public function isCartCurrent(): bool
    {
        return $this->isPlanCurrent()
            && $this->cart_sync_status === CartSyncStatus::Synced
            && $this->cart_synced_state_version === $this->state_version;
    }
}
