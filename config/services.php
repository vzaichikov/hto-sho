<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'silpo_mcp' => [
        'url' => env('SILPO_MCP_URL', 'https://mcp.silpo.ua/mcp'),
        'shop_url' => 'https://silpo.ua',
        'client_name' => env('SILPO_MCP_CLIENT_NAME', 'Хто Шо?'),
        'redirect_uri' => env(
            'SILPO_MCP_REDIRECT_URI',
            rtrim(env('APP_URL', 'http://127.0.0.1:8000'), '/').'/mcp/oauth/silpo/callback',
        ),
        'timeout' => (int) env('SILPO_MCP_TIMEOUT', 20),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'openai'),
        'model' => env('AI_MODEL', 'gpt-5.4-mini'),
        'api_key' => env('AI_API_KEY'),
        'request_timeout' => (int) env('AI_REQUEST_TIMEOUT', 60),
        'context_request_timeout' => (int) env('AI_CONTEXT_REQUEST_TIMEOUT', 75),
        'providers' => [
            'openai' => [
                'base_url' => 'https://api.openai.com/v1',
            ],
            'ollama' => [
                'base_url' => 'https://ollama.com/v1',
            ],
        ],
    ],

];
