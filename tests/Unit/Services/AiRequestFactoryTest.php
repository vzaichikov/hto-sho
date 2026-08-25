<?php

namespace Tests\Unit\Services;

use App\Services\AiRequestFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class AiRequestFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_it_builds_an_openai_request(): void
    {
        config()->set([
            'services.ai.provider' => 'openai',
            'services.ai.model' => 'gpt-5.4-mini',
            'services.ai.api_key' => 'openai-secret',
        ]);

        $factory = $this->app->make(AiRequestFactory::class);

        $factory->make()->post('responses', [
            'model' => $factory->model(),
            'input' => 'test',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer openai-secret')
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request['model'] === 'gpt-5.4-mini');
    }

    public function test_it_builds_an_ollama_cloud_request(): void
    {
        config()->set([
            'services.ai.provider' => 'ollama',
            'services.ai.model' => 'qwen3.5:397b',
            'services.ai.api_key' => 'ollama-secret',
        ]);

        $factory = $this->app->make(AiRequestFactory::class);

        $factory->make()->post('chat/completions', [
            'model' => $factory->model(),
            'messages' => [],
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ollama.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer ollama-secret')
            && $request['model'] === 'qwen3.5:397b');
    }

    public function test_it_uses_the_configured_default_and_explicit_request_timeouts(): void
    {
        config()->set([
            'services.ai.provider' => 'openai',
            'services.ai.api_key' => 'openai-secret',
            'services.ai.request_timeout' => 55,
        ]);

        $factory = $this->app->make(AiRequestFactory::class);

        $this->assertSame(55, $factory->make()->getOptions()['timeout']);
        $this->assertSame(75, $factory->make(75)->getOptions()['timeout']);
    }

    public function test_it_rejects_an_unsupported_provider(): void
    {
        config()->set([
            'services.ai.provider' => 'unsupported',
            'services.ai.api_key' => 'secret',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported AI provider [unsupported].');

        $this->app->make(AiRequestFactory::class)->make();
    }

    public function test_it_requires_an_api_key(): void
    {
        config()->set([
            'services.ai.provider' => 'openai',
            'services.ai.api_key' => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI API key is not configured.');

        $this->app->make(AiRequestFactory::class)->make();
    }

    public function test_it_requires_a_model(): void
    {
        config()->set('services.ai.model', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI model is not configured.');

        $this->app->make(AiRequestFactory::class)->model();
    }
}
