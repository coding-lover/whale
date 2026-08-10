<?php

return [
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', ''),
        'database' => env('REDIS_DATABASE', 0),
        'timeout' => env('REDIS_TIMEOUT', 5),
    ],
];
