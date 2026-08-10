<?php

return [
    'host' => '127.0.0.1',
    'port' => 9502,
    'type' => 'http',
    'settings' => [
        'worker_num' => 2,
        'max_request' => 1000,
        'task_worker_num' => 1,
        'enable_coroutine' => true,
        'open_tcp_nodelay' => true,
        'log_file' => LOG_PATH . '/swoole_dev.log',
        'pid_file' => RUNTIME_PATH . '/server_dev.pid',
    ],
];
