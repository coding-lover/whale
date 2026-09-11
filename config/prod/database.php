<?php

return [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', 3306),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'database' => env('DB_DATABASE', 'sikelan_prod'),
    'charset' => 'utf8mb4',
    'pool_size' => env('DB_POOL_SIZE', 20),
    'timeout' => env('DB_TIMEOUT', 5),
];
