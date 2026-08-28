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
            'nested' => [
                'client_secret' => 'secret',
                'encrypted_content' => 'opaque-reasoning-payload',
                'safe' => 'visible',
            ],
        ]);

        $this->assertSame('[REDACTED]', $payload['headers']['Authorization']);
        $this->assertSame('[REDACTED]', $payload['access_token']);
        $this->assertSame('[REDACTED]', $payload['nested']['client_secret']);
        $this->assertSame('[REDACTED]', $payload['nested']['encrypted_content']);
        $this->assertSame('visible', $payload['nested']['safe']);
    }

    public function test_it_discards_model_reasoning_items_but_keeps_semantic_output(): void
    {
        $payload = (new HarnessPayloadSanitizer)->sanitize([
            'output' => [
                [
                    'type' => 'reasoning',
                    'encrypted_content' => 'opaque-reasoning-payload',
                ],
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => '{"reason":"Корисне пояснення"}',
                    ]],
                ],
            ],
        ]);

        $this->assertCount(1, $payload['output']);
        $this->assertSame('message', $payload['output'][0]['type']);
        $this->assertSame(
            '{"reason":"Корисне пояснення"}',
            $payload['output'][0]['content'][0]['text'],
        );
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

    public function test_it_redacts_private_account_and_delivery_fields_but_keeps_cart_evidence(): void
    {
        $payload = (new HarnessPayloadSanitizer)->sanitize([
            'cart' => [
                'address' => ['street' => 'Private street', 'latitude' => '50.1'],
                'phone' => '+380000000000',
                'checkoutWebLink' => 'https://shop.example/private-cart',
                'checkoutMobileLink' => 'https://shop.example/private-cart-mobile',
                'calculation' => [
                    'totalAfterDiscounts' => 123.45,
                    'loyalty' => ['bonusAvailable' => 100],
                ],
                'shipments' => [['products' => [['name' => 'Вода', 'quantity' => 2]]]],
            ],
            'text' => json_encode([
                'cart' => [
                    'address' => ['street' => 'Private street'],
                    'calculation' => ['totalAfterDiscounts' => 123.45],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertSame('[REDACTED]', $payload['cart']['address']);
        $this->assertSame('[REDACTED]', $payload['cart']['phone']);
        $this->assertSame('[REDACTED]', $payload['cart']['checkoutWebLink']);
        $this->assertSame('[REDACTED]', $payload['cart']['checkoutMobileLink']);
        $this->assertSame('[REDACTED]', $payload['cart']['calculation']['loyalty']);
        $this->assertSame(123.45, $payload['cart']['calculation']['totalAfterDiscounts']);
        $this->assertSame('Вода', $payload['cart']['shipments'][0]['products'][0]['name']);
        $text = json_decode($payload['text'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('[REDACTED]', $text['cart']['address']);
        $this->assertSame(123.45, $text['cart']['calculation']['totalAfterDiscounts']);
    }
}
