<?php

/*
| The browser never calls the API directly: the web app proxies through its own server (BFF),
| and the admin panel is same-origin. So cross-origin browser access is limited to the web app's
| own origin (and local development), never "*".
*/
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter([
        rtrim((string) env('FRONTEND_URL', 'https://provokenow.com'), '/'),
        env('APP_ENV') === 'production' ? null : 'http://localhost:3000',
    ])),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-Cart-Token'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
