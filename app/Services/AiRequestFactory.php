<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class AiRequestFactory
{
    public function make(): PendingRequest
    {
        $provider = (string) config('services.ai.provider');
        $baseUrl = config("services.ai.providers.{$provider}.base_url");
        $apiKey = (string) config('services.ai.api_key');

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
            ->timeout(60);
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
