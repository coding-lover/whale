<?php

/**
 * 框架 env() 全局函数（独立文件，确保在 illuminate/support/helpers.php 之前加载）。
 *
 * ⚠️ 背景：
 * illuminate/support/helpers.php 定义了自己的 env()，会通过 phpdotenv 仓库
 * 读取 $_ENV/$_SERVER（优先于 getenv），与框架行为不一致。
 * 框架 env() 直接读 getenv()，而 common.php 加载 .env 时会同时写 getenv/$_ENV/$_SERVER，
 * 两者对 putenv() 的响应不同（框架立即响应，illuminate 可能仍读 $_ENV 旧值）。
 *
 * 解决：本文件通过 composer autoload.files 加载，并由 post-autoload-dump 脚本
 * 移到 vendor/composer/autoload_files.php 首位，确保 env() 先于 illuminate 定义。
 * illuminate 的 helpers.php 有 function_exists('env') 守卫，会自动跳过。
 */

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return $value;
    }
}
