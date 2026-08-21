<?php

namespace App\Services;

use Laravel\Mcp\Client\Exceptions\OAuthException;
use Laravel\Mcp\Client\OAuth\AuthServerMetadata;
use Laravel\Mcp\Client\OAuth\ClientRegistration;
use Laravel\Mcp\Client\OAuth\DynamicClientRegistration;
use Laravel\Mcp\Client\OAuth\Enums\TokenEndpointAuthMethod;
use Laravel\Mcp\Client\OAuth\OAuthClient;
use Laravel\Mcp\Client\OAuth\OAuthConfig;

class SilpoOAuthClient extends OAuthClient
{
    public function __construct()
    {
        parent::__construct(
            config: new OAuthConfig(
                redirectUri: (string) config('services.silpo_mcp.redirect_uri'),
            ),
            resourceUrl: (string) config('services.silpo_mcp.url'),
        );
    }

    protected function resolveScope(): ?string
    {
        return null;
    }

    protected function register(AuthServerMetadata $metadata, string $redirectUri): ClientRegistration
    {
        if ($metadata->registrationEndpoint === null) {
            throw new OAuthException('Silpo authorization server does not support dynamic client registration.');
        }

        $registration = (new DynamicClientRegistration)->register(
            registrationEndpoint: $metadata->registrationEndpoint,
            redirectUri: $redirectUri,
            scope: null,
            clientName: (string) config('services.silpo_mcp.client_name', 'Хто Шо?'),
            applicationType: $this->applicationType($redirectUri),
            tokenEndpointAuthMethod: TokenEndpointAuthMethod::None,
        );

        return new ClientRegistration(clientId: $registration->clientId);
    }
}
