<?php

namespace App\Services;

use App\HarnessEntryKind;
use App\HarnessRunStatus;
use App\HarnessRunType;
use App\Models\Event;
use App\Models\HarnessEntry;
use App\Models\HarnessRun;
use Illuminate\Support\Facades\DB;
use Throwable;

class HarnessRecorder
{
    public function __construct(private readonly HarnessPayloadSanitizer $sanitizer) {}

    /** @param array<string, mixed> $metadata */
    public function start(
        ?Event $event,
        HarnessRunType $type,
        string $correlationId,
        array $metadata = [],
    ): HarnessRun {
        $run = HarnessRun::query()->firstOrCreate([
            'event_id' => $event?->id,
            'type' => $type,
            'correlation_id' => $correlationId,
        ], [
            'status' => HarnessRunStatus::Running,
            'metadata' => $this->sanitizer->sanitize($metadata),
            'started_at' => now(),
        ]);

        if (! $run->wasRecentlyCreated && $run->status !== HarnessRunStatus::Running) {
            $run->update([
                'status' => HarnessRunStatus::Running,
                'error' => null,
                'finished_at' => null,
            ]);
        }

        return $run;
    }

    public function attach(HarnessRun $run, Event $event): void
    {
        $run->update(['event_id' => $event->id]);
    }

    /** @param array<string, mixed> $metadata */
    public function mergeMetadata(HarnessRun $run, array $metadata): void
    {
        $run->update([
            'metadata' => [
                ...($run->metadata ?? []),
                ...$this->sanitizer->sanitize($metadata),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $requestPayload
     * @param  array<string, mixed>|null  $responsePayload
     * @param  array<string, mixed>|null  $metadata
     */
    public function append(
        HarnessRun $run,
        HarnessEntryKind $kind,
        string $title,
        ?string $message = null,
        string $status = 'completed',
        ?string $method = null,
        ?string $endpoint = null,
        ?int $statusCode = null,
        ?int $durationMs = null,
        ?array $requestPayload = null,
        ?array $responsePayload = null,
        ?array $metadata = null,
    ): HarnessEntry {
        return DB::transaction(function () use (
            $run,
            $kind,
            $title,
            $message,
            $status,
            $method,
            $endpoint,
            $statusCode,
            $durationMs,
            $requestPayload,
            $responsePayload,
            $metadata,
        ): HarnessEntry {
            $lockedRun = HarnessRun::query()->lockForUpdate()->findOrFail($run->id);
            $sequence = $lockedRun->next_sequence;
            $lockedRun->increment('next_sequence');

            return $lockedRun->entries()->create([
                'sequence' => $sequence,
                'kind' => $kind,
                'status' => $status,
                'title' => $title,
                'message' => $message,
                'method' => $method,
                'endpoint' => $endpoint,
                'status_code' => $statusCode,
                'duration_ms' => $durationMs,
                'request_payload' => $requestPayload === null ? null : $this->sanitizer->sanitize($requestPayload),
                'response_payload' => $responsePayload === null ? null : $this->sanitizer->sanitize($responsePayload),
                'metadata' => $metadata === null ? null : $this->sanitizer->sanitize($metadata),
            ]);
        });
    }

    /** @param array<string, mixed> $requestPayload */
    public function startExternal(
        HarnessRun $run,
        HarnessEntryKind $kind,
        string $title,
        string $method,
        string $endpoint,
        array $requestPayload,
    ): HarnessEntry {
        return $this->append(
            run: $run,
            kind: $kind,
            title: $title,
            status: 'running',
            method: $method,
            endpoint: $endpoint,
            requestPayload: $requestPayload,
        );
    }

    /** @param array<string, mixed> $responsePayload */
    public function completeExternal(
        HarnessEntry $entry,
        array $responsePayload,
        ?int $statusCode,
        int $durationMs,
    ): void {
        $entry->update([
            'status' => 'completed',
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'response_payload' => $this->sanitizer->sanitize($responsePayload),
        ]);
    }

    public function failExternal(HarnessEntry $entry, Throwable $exception, int $durationMs): void
    {
        $entry->update([
            'status' => 'failed',
            'duration_ms' => $durationMs,
            'response_payload' => $this->sanitizer->sanitize([
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]),
        ]);
    }

    public function finish(HarnessRun $run): void
    {
        $run->update([
            'status' => HarnessRunStatus::Completed,
            'finished_at' => now(),
        ]);
    }

    public function fail(HarnessRun $run, Throwable|string|null $error): void
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        $run->update([
            'status' => HarnessRunStatus::Failed,
            'error' => $message,
            'finished_at' => now(),
        ]);

        $this->append(
            run: $run,
            kind: HarnessEntryKind::Error,
            title: 'Виконання завершилося помилкою',
            message: $message,
        );
    }
}
