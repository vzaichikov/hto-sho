<?php

namespace App\Actions;

use App\Contracts\SilpoProfileGateway;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Client\OAuth\TokenSet;
use RuntimeException;

class AuthenticateSilpoUserAction
{
    public function __construct(private SilpoProfileGateway $profiles) {}

    public function execute(TokenSet $token): User
    {
        $profile = $this->profiles->getProfile($token->accessToken);
        [$identityType, $identityValue] = $this->identityFrom($profile);
        $identityHash = hash_hmac('sha256', "{$identityType}:{$identityValue}", (string) config('app.key'));
        $name = $this->displayNameFrom($profile);

        return DB::transaction(function () use ($identityHash, $name, $profile, $token): User {
            $user = User::query()->firstOrNew(['silpo_identity_hash' => $identityHash]);
            $user->name = $name;
            $user->email = null;
            $user->password = null;
            $user->save();

            $existingRefreshToken = $user->silpoConnection()->first()?->refresh_token;

            $user->silpoConnection()->updateOrCreate([], [
                'client_id' => $token->clientId,
                'client_secret' => $token->clientSecret,
                'access_token' => $token->accessToken,
                'refresh_token' => $token->refreshToken ?? $existingRefreshToken,
                'token_type' => $token->tokenType,
                'scope' => $token->scope,
                'expires_at' => $token->expiresAt === null
                    ? null
                    : CarbonImmutable::createFromTimestamp($token->expiresAt),
                'profile_snapshot' => $profile,
                'profile_synced_at' => now(),
                'last_verified_at' => now(),
                'revoked_at' => null,
            ]);

            return $user->load('silpoConnection');
        });
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{string, string}
     */
    private function identityFrom(array $profile): array
    {
        $stableId = $this->firstScalar($profile, [
            'id',
            'userId',
            'user_id',
            'guestId',
            'guest_id',
            'profile.id',
            'profile.userId',
            'profile.guestId',
            'data.id',
            'data.userId',
            'data.guestId',
            'data.profile.id',
        ]);

        if ($stableId !== null) {
            return ['id', Str::lower(trim($stableId))];
        }

        $phone = $this->firstScalar($profile, ['phone', 'phoneNumber', 'profile.phone', 'data.phone', 'data.profile.phone']);

        if ($phone !== null && ($normalizedPhone = preg_replace('/\D+/', '', $phone)) !== '') {
            return ['phone', $normalizedPhone];
        }

        $email = $this->firstScalar($profile, ['email', 'profile.email', 'data.email', 'data.profile.email']);

        if ($email !== null) {
            $normalizedEmail = Str::lower(trim($email));

            if (filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) !== false) {
                return ['email', $normalizedEmail];
            }
        }

        throw new RuntimeException('Профіль Сільпо не містить стабільного ідентифікатора, телефону або email.');
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function displayNameFrom(array $profile): string
    {
        $name = $this->firstScalar($profile, [
            'name',
            'fullName',
            'displayName',
            'profile.name',
            'profile.fullName',
            'data.name',
            'data.fullName',
            'data.profile.name',
        ]);

        if ($name !== null) {
            return Str::squish($name);
        }

        $firstName = $this->firstScalar($profile, ['firstName', 'first_name', 'profile.firstName', 'data.firstName']);
        $lastName = $this->firstScalar($profile, ['lastName', 'last_name', 'profile.lastName', 'data.lastName']);
        $combinedName = Str::squish(implode(' ', array_filter([$firstName, $lastName])));

        return $combinedName !== '' ? $combinedName : 'Гість Сільпо';
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<int, string>  $paths
     */
    private function firstScalar(array $profile, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = Arr::get($profile, $path);

            if ((is_string($value) || is_int($value)) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
