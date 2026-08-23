<?php

namespace App\Services;

use App\CartRunStatus;
use App\HarnessEntryKind;
use App\Models\EventCartRun;
use App\Models\EventCartRunStep;
use Illuminate\Support\Str;
use RuntimeException;

final class GooseCartStatusService
{
    public function __construct(private readonly ?HarnessRecorder $harnessRecorder = null) {}

    /** @param array<string, mixed> $context */
    public function append(
        EventCartRun $run,
        string $kind,
        ?string $product = null,
        array $context = [],
    ): EventCartRunStep {
        $sequence = ((int) $run->steps()->max('sequence')) + 1;
        $previousMessage = $run->steps()->latest('sequence')->value('message');

        $step = $run->steps()->create([
            'sequence' => $sequence,
            'kind' => $kind,
            'message' => $this->phrase($kind, $run->id, $sequence, $product, $previousMessage),
            'context' => $context,
        ]);

        $harnessRun = $run->harnessRun()->first();

        if ($harnessRun !== null && $this->harnessRecorder !== null) {
            $this->harnessRecorder->append(
                run: $harnessRun,
                kind: $kind === 'blocked' ? HarnessEntryKind::Question : HarnessEntryKind::Action,
                title: $step->message,
                message: $kind === 'blocked' ? $run->blocker : null,
                metadata: ['cart_run_id' => $run->id, 'step_kind' => $kind, ...$context],
            );

            if ($run->status->isTerminal() && in_array($kind, ['success', 'warning'], true)) {
                if ($run->status === CartRunStatus::Failed) {
                    $this->harnessRecorder->fail($harnessRun, $run->error);
                } else {
                    $this->harnessRecorder->finish($harnessRun);
                }
            }
        }

        return $step;
    }

    public function phrase(
        string $kind,
        int $runId,
        int $sequence,
        ?string $product = null,
        ?string $previousMessage = null,
    ): string {
        $phrases = config("goose_cart_phrases.{$kind}");

        if (! is_array($phrases) || $phrases === []) {
            throw new RuntimeException("Unknown Goose cart phrase category [{$kind}].");
        }

        $index = abs(crc32("{$runId}:{$kind}:{$sequence}")) % count($phrases);
        $phrase = $phrases[$index];
        $safeProduct = Str::of($product ?? 'цей товар')->squish()->limit(100, '…')->toString();
        $message = str_replace(['%product%', '%product name%'], $safeProduct, $phrase);

        if ($message === $previousMessage && count($phrases) > 1) {
            $phrase = $phrases[($index + 1) % count($phrases)];
            $message = str_replace(['%product%', '%product name%'], $safeProduct, $phrase);
        }

        return $message;
    }
}
