<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class AiRequestFactory
{
    public function make(?int $timeoutSeconds = null): PendingRequest
    {
        $provider = (string) config('services.ai.provider');
        $baseUrl = config("services.ai.providers.{$provider}.base_url");
        $apiKey = $provider === 'ollama'
            ? (string) config('services.ai.providers.ollama.api_key')
            : (string) config('services.ai.api_key');
        $timeoutSeconds ??= (int) config('services.ai.request_timeout', 60);

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new InvalidArgumentException("Unsupported AI provider [{$provider}].");
        }

        if ($apiKey === '') {
            throw new RuntimeException('AI API key is not configured.');
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->withToken($apiKey)
            ->connectTimeout(10)
            ->timeout($timeoutSeconds);
    }

    public function model(): string
    {
        $model = (string) config('services.ai.model');

        if ($model === '') {
            throw new RuntimeException('AI model is not configured.');
        }

        return $model;
    }
}
