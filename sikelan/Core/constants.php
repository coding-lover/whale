<?php

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));

defined('APP_PATH') || define('APP_PATH', BASE_PATH . '/app');

defined('CONFIG_PATH') || define('CONFIG_PATH', BASE_PATH . '/config');

defined('RUNTIME_PATH') || define('RUNTIME_PATH', BASE_PATH . '/runtime');

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
