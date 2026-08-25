<?php

namespace App\Models;

use App\CartSyncStatus;
use App\EventAnalysisStage;
use App\EventStatus;
use App\PlanGenerationStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable([
    'user_id',
    'title',
    'description',
    'alcohol_planned',
    'people_count',
    'status',
    'state',
    'state_version',
    'evidence_version',
    'state_evidence_version',
    'shopping_plan',
    'plan_state_version',
    'plan_generation_status',
    'plan_generation_error',
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
        'alcohol_planned' => false,
        'currency' => 'UAH',
        'cart_sync_status' => 'not_synced',
        'plan_generation_status' => 'not_started',
    ];

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'state' => 'array',
            'alcohol_planned' => 'boolean',
            'state_version' => 'integer',
            'evidence_version' => 'integer',
            'state_evidence_version' => 'integer',
            'shopping_plan' => 'array',
            'plan_state_version' => 'integer',
            'plan_generation_status' => PlanGenerationStatus::class,
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

    public function contextVersions(): HasMany
    {
        return $this->hasMany(EventContextVersion::class);
    }

    public function cartRuns(): HasMany
    {
        return $this->hasMany(EventCartRun::class);
    }

    public function silpoCartResets(): HasMany
    {
        return $this->hasMany(SilpoCartReset::class);
    }

    public function harnessRuns(): HasMany
    {
        return $this->hasMany(HarnessRun::class);
    }

    public function latestCartRun(): HasOne
    {
        return $this->hasOne(EventCartRun::class)->latestOfMany();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function unansweredQuestions(): Collection
    {
        $answerSources = ($this->relationLoaded('sources') ? $this->sources : $this->sources()->get())
            ->where('origin', 'question_answer');
        $answeredQuestionKeys = $answerSources
            ->pluck('metadata.question_key')
            ->filter(fn (mixed $key): bool => is_string($key))
            ->unique();

        return collect($this->state['unresolved_questions'] ?? [])
            ->filter(fn (mixed $question): bool => is_array($question)
                && is_string($question['key'] ?? null)
                && isset($question['impact'], $question['options'])
                && is_array($question['options']))
            ->reject(fn (array $question): bool => $answeredQuestionKeys->containsStrict($question['key'])
                || $this->questionMatchesRecordedAnswer($question, $answerSources))
            ->values();
    }

    public function questionsNeedRefresh(): bool
    {
        $rawQuestions = collect($this->state['unresolved_questions'] ?? []);

        return $rawQuestions->filter(fn (mixed $question): bool => is_array($question)
            && is_string($question['key'] ?? null)
            && isset($question['impact'], $question['options'])
            && is_array($question['options']))->count() !== $rawQuestions->count();
    }

    /** @param Collection<int, EventSource> $answerSources */
    private function questionMatchesRecordedAnswer(array $question, Collection $answerSources): bool
    {
        $sourceIds = collect($question['source_ids'] ?? [])->filter(fn (mixed $id): bool => is_int($id));
        $optionLabels = collect($question['options'] ?? [])
            ->pluck('label')
            ->filter(fn (mixed $label): bool => is_string($label))
            ->map(fn (string $label): string => Str::lower(Str::squish($label)));

        if ($sourceIds->isEmpty() || $optionLabels->isEmpty()) {
            return false;
        }

        return $answerSources
            ->whereIn('id', $sourceIds)
            ->contains(function (EventSource $source) use ($optionLabels): bool {
                $answer = data_get($source->metadata, 'answer');

                return is_string($answer)
                    && $optionLabels->containsStrict(Str::lower(Str::squish($answer)));
            });
    }

    public function isPlanCurrent(): bool
    {
        return $this->status === EventStatus::Ready
            && ! $this->hasUnanalyzedChanges()
            && $this->shopping_plan !== null
            && $this->plan_state_version === $this->state_version
            && $this->plan_generation_status === PlanGenerationStatus::Ready;
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
