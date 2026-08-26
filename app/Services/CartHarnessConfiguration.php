<?php

namespace App\Services;

use App\CartHarnessMode;
use InvalidArgumentException;

final class CartHarnessConfiguration
{
    /** @var array<int, string> */
    private const REASONING_EFFORTS = ['none', 'low', 'medium', 'high', 'xhigh', 'max'];

    public function mode(): CartHarnessMode
    {
        $value = (string) config('services.silpo_cart_harness.mode', CartHarnessMode::Orchestrated->value);
        $mode = CartHarnessMode::tryFrom($value);

        if ($mode === null) {
            throw new InvalidArgumentException("Unsupported Silpo cart harness mode [{$value}].");
        }

        return $mode;
    }

    public function assertReady(CartHarnessMode $mode): void
    {
        if ($mode !== CartHarnessMode::Agentic) {
            return;
        }

        if (config('services.ai.provider') !== 'openai') {
            throw new InvalidArgumentException('Agentic Silpo cart mode requires the OpenAI provider.');
        }

        $this->model();
        $this->reasoningEffort();
        $this->requestTimeout();
        $this->maxToolCallsPerNeed();
    }

    public function model(): string
    {
        $model = (string) config('services.silpo_cart_harness.model');

        if ($model === '') {
            throw new InvalidArgumentException('Agentic Silpo cart model is not configured.');
        }

        return $model;
    }

    public function reasoningEffort(): string
    {
        $effort = (string) config('services.silpo_cart_harness.reasoning_effort', 'high');

        if (! in_array($effort, self::REASONING_EFFORTS, true)) {
            throw new InvalidArgumentException("Unsupported agentic reasoning effort [{$effort}].");
        }

        return $effort;
    }

    public function requestTimeout(): int
    {
        $timeout = (int) config('services.silpo_cart_harness.request_timeout', 150);

        if ($timeout < 1 || $timeout > 150) {
            throw new InvalidArgumentException('Agentic Silpo cart timeout must be between 1 and 150 seconds.');
        }

        return $timeout;
    }

    public function maxToolCallsPerNeed(): int
    {
        $maxToolCalls = (int) config('services.silpo_cart_harness.max_tool_calls_per_need', 12);

        if ($maxToolCalls < 1 || $maxToolCalls > 30) {
            throw new InvalidArgumentException('Agentic Silpo cart tool-call limit must be between 1 and 30.');
        }

        return $maxToolCalls;
    }
}
