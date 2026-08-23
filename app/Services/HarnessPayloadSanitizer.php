<?php

namespace App\Services;

use Illuminate\Support\Str;

class HarnessPayloadSanitizer
{
    /** @var array<int, string> */
    private const SENSITIVE_KEY_PARTS = [
        'authorization',
        'access_token',
        'refresh_token',
        'api_key',
        'client_secret',
        'password',
        'cookie',
        'csrf',
        'certificate',
    ];

    public function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $sanitized[$key] = is_string($key) && $this->isSensitiveKey($key)
                    ? '[REDACTED]'
                    : $this->sanitize($item);
            }

            return $sanitized;
        }

        if (! is_string($value)) {
            return $value;
        }

        if (preg_match('/^data:(image\/[a-z0-9.+-]+);base64,(.*)$/is', $value, $matches) === 1) {
            $decoded = base64_decode($matches[2], true);

            return [
                'redacted' => true,
                'mime_type' => Str::lower($matches[1]),
                'bytes' => $decoded === false ? null : strlen($decoded),
                'sha256' => hash('sha256', $decoded === false ? $matches[2] : $decoded),
            ];
        }

        if (preg_match('/^Bearer\s+\S+$/i', $value) === 1) {
            return '[REDACTED]';
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = Str::lower($key);

        return collect(self::SENSITIVE_KEY_PARTS)
            ->contains(fn (string $part): bool => Str::contains($normalized, $part));
    }
}
