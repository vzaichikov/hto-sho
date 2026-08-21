<?php

namespace App\Contracts;

interface SilpoProfileGateway
{
    /**
     * @return array<string, mixed>
     */
    public function getProfile(string $accessToken): array;
}
