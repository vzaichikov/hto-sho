<?php

namespace App\Services;

use App\Contracts\SilpoProfileGateway;
use JsonException;
use Laravel\Mcp\Client;
use RuntimeException;

class McpSilpoProfileGateway implements SilpoProfileGateway
{
    public function getProfile(string $accessToken): array
    {
        $client = Client::web((string) config('services.silpo_mcp.url'))
            ->withToken($accessToken)
            ->withTimeout((float) config('services.silpo_mcp.timeout', 20));

        try {
            $result = $client->callTool('silpo_get_my_profile');
        } finally {
            $client->disconnect();
        }

        if ($result->isError) {
            throw new RuntimeException('Сільпо не повернуло профіль гостя.');
        }

        if ($result->structuredContent !== null) {
            return $result->structuredContent;
        }

        try {
            $profile = json_decode($result->text(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Профіль Сільпо має неочікуваний формат.', previous: $exception);
        }

        if (! is_array($profile)) {
            throw new RuntimeException('Профіль Сільпо має неочікуваний формат.');
        }

        return $profile;
    }
}
