<?php

namespace App\Services;

use Illuminate\Support\Str;
use JsonException;

class HarnessPayloadSanitizer
{
    /** @var array<int, string> */
    private const SENSITIVE_KEY_PARTS = [
        'authorization',
        'access_token',
        'refresh_token',
        'api_key',
        'client_secret',
        'encrypted_content',
        'password',
        'cookie',
        'csrf',
        'certificate',
        'address',
        'latitude',
        'longitude',
        'phone',
        'email',
        'birthday',
        'checkout',
        'loyalty',
    ];

    public function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            $isList = array_is_list($value);

            foreach ($value as $key => $item) {
                if ($isList && is_array($item) && data_get($item, 'type') === 'reasoning') {
                    continue;
                }

                $sanitizedItem = is_string($key) && $this->isSensitiveKey($key)
                    ? '[REDACTED]'
                    : $this->sanitize($item);

                if ($isList) {
                    $sanitized[] = $sanitizedItem;
                } else {
                    $sanitized[$key] = $sanitizedItem;
                }
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

        if (Str::startsWith(ltrim($value), ['{', '['])) {
            try {
                $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    return json_encode(
                        $this->sanitize($decoded),
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    );
                }
            } catch (JsonException) {
                return $value;
            }
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
