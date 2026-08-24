<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final class SilpoFulfilmentTokenService
{
    /** @param array<string, mixed> $payload */
    public function issue(string $purpose, User $user, Event $event, array $payload): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'purpose' => $purpose,
            'user_id' => $user->id,
            'event_id' => $event->id,
            'nonce' => (string) Str::ulid(),
            'expires_at' => now()->addMinutes(15)->timestamp,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    public function decode(string $token, string $purpose, User $user, Event $event): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new RuntimeException('Цей маршрут загубив пірʼїнку дорогою. Перевірте його ще раз.');
        }

        if (! is_array($decoded)
            || Arr::get($decoded, 'version') !== 1
            || Arr::get($decoded, 'purpose') !== $purpose
            || (int) Arr::get($decoded, 'user_id') !== $user->id
            || (int) Arr::get($decoded, 'event_id') !== $event->id
            || (int) Arr::get($decoded, 'expires_at') < now()->timestamp
            || ! is_array(Arr::get($decoded, 'payload'))) {
            throw new RuntimeException('Цей маршрут уже неактуальний. Гусь просить перевірити його ще раз.');
        }

        return Arr::get($decoded, 'payload');
    }
}
