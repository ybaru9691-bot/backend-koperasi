<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', '*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://koperasiapp-production.up.railway.app',
        'https://koperasi-frontend-production.up.railway.app',
        'http://localhost:3000',
        'http://localhost:8080',
        'http://127.0.0.1:8000',
    ],

    'allowed_origins_patterns' => [
        '#^https?://localhost:\d+$#',
        '#^https?://127\.0\.0\.1:\d+$#',
        '#^https://.*\.up\.railway\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Authorization', 'X-Total-Count'],

    'max_age' => 86400,

    'supports_credentials' => true,

];