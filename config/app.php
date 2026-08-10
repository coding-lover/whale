<?php

return [
    'name' => env('APP_NAME', 'Sikelan'),
    'debug' => env('APP_DEBUG', true),
    'log_level' => env('APP_LOG_LEVEL', 'debug'),
    'log_path' => env('APP_LOG_PATH', LOG_PATH),
    'log_channel' => env('APP_LOG_CHANNEL', 'app'),

    // Hook 类，用于自定义事件回调和自定义进程
    // 不配置时使用框架默认的事件回调
    // 示例：\App\Hooks\AppHook::class
    'hook' => env('APP_HOOK', ''),
];
