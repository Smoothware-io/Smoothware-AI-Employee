<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    /*
    | Embeddings provider for RAG (Phase 2). Anthropic's API is generation-only,
    | so embeddings come from a separate provider. Defaults to an offline fake
    | for dev/tests; set EMBEDDINGS_DRIVER=voyage + VOYAGE_API_KEY in production.
    */
    'embeddings' => [
        'driver' => env('EMBEDDINGS_DRIVER', 'fake'),
        'voyage' => [
            'key' => env('VOYAGE_API_KEY'),
            // voyage-3 is retired; current lineup is voyage-4*/voyage-3.5*/voyage-3-large.
            'model' => env('VOYAGE_MODEL', 'voyage-3.5'),
            'dimensions' => (int) env('VOYAGE_DIMENSIONS', 1024),
        ],
    ],

    /*
    | Google Calendar — so the AI never books over a rep's real meetings.
    |
    | Per user, not per install: the calendar being protected is personal, and a
    | single shared service account would make every rep's private appointments
    | readable by everyone in the CRM.
    |
    | Absent credentials = the feature is simply off. Nothing breaks; availability
    | falls back to the CRM's own working hours and appointments.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        // Short by design: freeBusy runs while a caller waits in silence.
        'timeout' => (int) env('GOOGLE_TIMEOUT', 5),
        // Long enough that a chatty call does not hammer Google, short enough
        // that a meeting added five minutes ago still blocks.
        'busy_cache_seconds' => (int) env('GOOGLE_BUSY_CACHE_SECONDS', 60),
    ],
    /*
    | Anthropic Claude — reasoning/analysis for the AI receptionist (Phase 3+).
    | Generation only (embeddings live under 'embeddings' above).
    */
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL_REASONING', 'claude-opus-4-8'),
        'effort' => env('ANTHROPIC_EFFORT', 'high'),
    ],
];
