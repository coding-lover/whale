<?php

return [
    'host' => env('SERVER_HOST', '0.0.0.0'),
    'port' => env('SERVER_PORT', 9502),
    'type' => env('SERVER_TYPE', 'http'),
    'settings' => [
        'worker_num' => env('SERVER_WORKER_NUM', swoole_cpu_num() * 2),
        'max_request' => env('SERVER_MAX_REQUEST', 10000),
        'task_worker_num' => env('SERVER_TASK_WORKER_NUM', swoole_cpu_num()),
        'enable_coroutine' => true,
        'open_tcp_nodelay' => true,
        'log_file' => env('SERVER_LOG_FILE', LOG_PATH . '/swoole.log'),
        'pid_file' => env('SERVER_PID_FILE', RUNTIME_PATH . '/server.pid'),
    ],
];
