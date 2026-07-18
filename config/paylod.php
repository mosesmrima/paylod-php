<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | API key
    |--------------------------------------------------------------------------
    |
    | Your paylod API key (mp_live_... or mp_test_...). This key can move money -
    | keep it in your server environment, never in client-side code.
    |
    */
    'api_key' => env('PAYLOD_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | Identical for every paylod customer, so you normally leave this alone.
    | Override only to point at a local stub in tests or a self-hosted backend.
    |
    */
    'base_url' => env('PAYLOD_BASE_URL', \Paylod\Paylod::DEFAULT_BASE_URL),

    /*
    |--------------------------------------------------------------------------
    | Webhook signing secret
    |--------------------------------------------------------------------------
    |
    | Shown once when you create a webhook endpoint. Needed only to verify
    | incoming webhooks.
    |
    */
    'webhook_secret' => env('PAYLOD_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | HTTP tuning
    |--------------------------------------------------------------------------
    */
    'timeout_ms' => (int) env('PAYLOD_TIMEOUT_MS', 30000),
    'max_retries' => (int) env('PAYLOD_MAX_RETRIES', 2),

    /*
    |--------------------------------------------------------------------------
    | Simulator mode (tests only)
    |--------------------------------------------------------------------------
    |
    | When true, collect()/collectAndWait() create a simulated sandbox payment
    | instead of ringing a phone. Requires a mp_test_ key - the client throws
    | otherwise, so this can never point at production.
    |
    */
    'simulate' => (bool) env('PAYLOD_SIMULATE', false),
];
