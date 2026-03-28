<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lodestone Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL for Final Fantasy XIV Lodestone.
    | Regional variants: na, eu, jp, fr, de
    |
    */

    'base_url' => env('LODESTONE_BASE_URL', 'https://na.finalfantasyxiv.com/lodestone'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the HTTP client used to fetch Lodestone pages.
    |
    */

    'http' => [
        'timeout' => env('LODESTONE_TIMEOUT', 30),
        'retry_times' => env('LODESTONE_RETRY_TIMES', 3),
        'retry_sleep' => env('LODESTONE_RETRY_SLEEP', 1000), // milliseconds
        'user_agent' => env('LODESTONE_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Optional delay between requests to be respectful to Lodestone servers.
    | Value in milliseconds.
    |
    */

    'rate_limit_delay' => env('LODESTONE_RATE_LIMIT_DELAY', 500),

    /*
    |--------------------------------------------------------------------------
    | Parsing Configuration
    |--------------------------------------------------------------------------
    |
    | Enable/disable specific parsing features.
    |
    */

    'parse_class_jobs' => env('LODESTONE_PARSE_CLASS_JOBS', true),

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Optional caching for scraped data to reduce load on Lodestone.
    | Set to 0 or null to disable caching.
    |
    */

    'cache_ttl' => env('LODESTONE_CACHE_TTL', 300), // seconds

];
