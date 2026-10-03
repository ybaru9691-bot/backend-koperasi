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

    'paths' => ['api/*', 'sanctum/csrf-cookie', '*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://koperasi-frontend-production.up.railway.app',
        'https://koperasiapp-production.up.railway.app',
        'http://localhost:3000',
        'http://localhost:8080',
        'http://10.0.2.2:*',
    ],

    'allowed_origins_patterns' => [
        '#https?://.*\.up\.railway\.app#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Authorization', 'X-Total-Count'],

    'max_age' => 0,

    'supports_credentials' => true,

];

