<?php

namespace Tests\Unit;

use App\Services\HarnessPayloadSanitizer;
use PHPUnit\Framework\TestCase;

class HarnessPayloadSanitizerTest extends TestCase
{
    public function test_it_redacts_secrets_recursively(): void
    {
        $payload = (new HarnessPayloadSanitizer)->sanitize([
            'headers' => ['Authorization' => 'Bearer secret-token'],
            'access_token' => 'token',
            'nested' => ['client_secret' => 'secret', 'safe' => 'visible'],
        ]);

        $this->assertSame('[REDACTED]', $payload['headers']['Authorization']);
        $this->assertSame('[REDACTED]', $payload['access_token']);
        $this->assertSame('[REDACTED]', $payload['nested']['client_secret']);
        $this->assertSame('visible', $payload['nested']['safe']);
    }

    public function test_it_replaces_image_data_with_safe_metadata(): void
    {
        $bytes = 'binary-image-contents';
        $payload = (new HarnessPayloadSanitizer)->sanitize([
            'image_url' => 'data:image/png;base64,'.base64_encode($bytes),
        ]);

        $this->assertSame([
            'redacted' => true,
            'mime_type' => 'image/png',
            'bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
        ], $payload['image_url']);
    }
}
