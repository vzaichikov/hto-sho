<?php

namespace Tests\Unit;

use App\CartHarnessMode;
use App\Services\CartHarnessConfiguration;
use InvalidArgumentException;
use Tests\TestCase;

class CartHarnessConfigurationTest extends TestCase
{
    public function test_missing_mode_resolves_to_orchestrated(): void
    {
        config(['services.silpo_cart_harness' => []]);

        $this->assertSame(CartHarnessMode::Orchestrated, app(CartHarnessConfiguration::class)->mode());
    }

    public function test_agentic_defaults_match_the_v2_profile(): void
    {
        config([
            'services.ai.provider' => 'openai',
            'services.silpo_cart_harness.mode' => 'agentic',
            'services.silpo_cart_harness.model' => 'gpt-5.6-luna',
            'services.silpo_cart_harness.reasoning_effort' => 'high',
            'services.silpo_cart_harness.request_timeout' => 150,
            'services.silpo_cart_harness.max_tool_calls_per_need' => 12,
        ]);
        $configuration = app(CartHarnessConfiguration::class);

        $configuration->assertReady($configuration->mode());

        $this->assertSame('gpt-5.6-luna', $configuration->model());
        $this->assertSame('high', $configuration->reasoningEffort());
        $this->assertSame(150, $configuration->requestTimeout());
        $this->assertSame(12, $configuration->maxToolCallsPerNeed());
    }

    public function test_invalid_mode_fails_clearly(): void
    {
        config(['services.silpo_cart_harness.mode' => 'php-but-more-agent-ish']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported Silpo cart harness mode');

        app(CartHarnessConfiguration::class)->mode();
    }

    public function test_agentic_mode_rejects_a_non_openai_provider(): void
    {
        config([
            'services.ai.provider' => 'ollama',
            'services.silpo_cart_harness.mode' => 'agentic',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires the OpenAI provider');

        app(CartHarnessConfiguration::class)->assertReady(CartHarnessMode::Agentic);
    }
}
