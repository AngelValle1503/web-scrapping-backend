<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))),

    // Capacitor Android loads the bundled app from this local HTTPS origin.
    // Keeping it as a pattern lets the production web origin remain controlled
    // separately through CORS_ALLOWED_ORIGINS.
    'allowed_origins_patterns' => array_filter(explode(',', env('CORS_ALLOWED_ORIGIN_PATTERNS', '#^https://localhost$#'))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
