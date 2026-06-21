<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // '*' : bazı prod ortamlarda `api/*` ile eslesme sorunu olusup preflight HTML'e dusuyordu.
    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(
        array_filter(
            array_map(
                static fn (string $v): string => trim($v, " \t\n\r\0\x0B\"'"),
                explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://127.0.0.1:3000,http://hakankekec.me,http://hakankekec.me:3000,https://hakankekec.me,https://hakankekec.me:3000'))
            )
        )
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
