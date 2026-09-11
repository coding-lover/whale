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
        // Task 进程内要跑协程客户端（交易所 HTTP 下载/回测数据拉取），必须开启
        'task_enable_coroutine' => env('SERVER_TASK_ENABLE_COROUTINE', true),
        'open_tcp_nodelay' => true,
        'log_file' => env('SERVER_LOG_FILE', __DIR__ . '/../logs/swoole.log'),
        'pid_file' => env('SERVER_PID_FILE', __DIR__ . '/../runtime/server.pid'),
    ],
];
