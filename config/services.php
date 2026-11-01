<?php

return [
    'qdrant' => [
        'url' => env('QDRANT_URL'),
        'api_key' => env('QDRANT_API_KEY'),
        'collection' => env('QDRANT_COLLECTION', 'battlefront_products'),
        'timeout_seconds' => env('QDRANT_TIMEOUT_SECONDS', 10),
    ],
    'neo4j' => [
        'uri' => env('NEO4J_URI'),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD'),
        'database' => env('NEO4J_DATABASE', 'neo4j'),
    ],
    /*
     * Preparation only: these roles do not activate a three-stage pipeline.
     * The existing AI SDK continues to use GEMINI_API_KEY independently.
     * Keys may share project quotas; never rotate them to bypass limits.
     */
    'gemini' => [
        // Future scope/intent screening in English, Tagalog, Bisaya and Hiligaynon.
        'scope' => ['api_key' => env('GEMINI_SCOPE_API_KEY')],
        // Future catalog-grounded replies with relevant history and retrieval context.
        'response' => ['api_key' => env('GEMINI_RESPONSE_API_KEY')],
        // Future grounding/compatibility checks returning accept, revise or reject.
        'validation' => ['api_key' => env('GEMINI_VALIDATION_API_KEY')],
    ],
    'paymongo' => [
        'enabled' => env('PAYMONGO_ENABLED', false),
        'mode' => env('PAYMONGO_MODE', 'test'),
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'public_key' => env('PAYMONGO_PUBLIC_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'hosted_methods' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAYMONGO_HOSTED_METHODS', 'card,gcash,paymaya,qrph'))))),
        'direct_banking_enabled' => env('PAYMONGO_DIRECT_BANKING_ENABLED', false),
        'direct_banking_banks' => ['bpi' => 'BPI', 'ubp' => 'UnionBank', 'landbank' => 'LandBank'],
    ],
    'expo' => [
        'enabled' => env('EXPO_PUSH_ENABLED', false),
        'access_token' => env('EXPO_PUSH_ACCESS_TOKEN'),
        'ca_bundle' => env('EXPO_PUSH_CA_BUNDLE'),
    ],

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

];
