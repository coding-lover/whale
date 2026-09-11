<?php

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));

defined('APP_PATH') || define('APP_PATH', BASE_PATH . '/app');

defined('CONFIG_PATH') || define('CONFIG_PATH', BASE_PATH . '/config');

// 运行时目录：运行时依赖的静态数据 + 临时产物（PID、缓存、回测导出等）统一放入 app/runtime/。
// 项目约定详见 .trae/rules/global-style.md §12「运行时静态数据目录约定」。
defined('RUNTIME_PATH') || define('RUNTIME_PATH', APP_PATH . '/runtime');

defined('LOG_PATH') || define('LOG_PATH', BASE_PATH . '/logs');

defined('FRAMEWORK_PATH') || define('FRAMEWORK_PATH', BASE_PATH . '/sikelan');

defined('VENDOR_PATH') || define('VENDOR_PATH', BASE_PATH . '/vendor');

defined('STORAGE_PATH') || define('STORAGE_PATH', BASE_PATH . '/storage');

if (!file_exists(RUNTIME_PATH)) {
    mkdir(RUNTIME_PATH, 0755, true);
}

if (!file_exists(LOG_PATH)) {
    mkdir(LOG_PATH, 0755, true);
}
