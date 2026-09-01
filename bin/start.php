#!/usr/bin/env php
<?php

/**
 * Swoole HTTP Server 启动入口（生产/开发共用）。
 *
 * ⭐ 入口保持精简：文件加载交给 Bootstrap；业务逻辑交给 Framework。
 * 参数解析（--env / mode）保留在此层（和具体启动行为强相关，不属于通用引导职责）。
 */

use Sikelan\Core\Bootstrap;
use Sikelan\Framework;

// 引导：autoload → constants → common → app common → xdebug（CLI 下会提示）
require __DIR__ . '/../sikelan/Core/Bootstrap.php';
Bootstrap::cli(__DIR__, true);

// ---- 命令行参数解析 ----
$mode        = 'http';
$environment = '';
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (strpos($arg, '--env=') === 0) {
        $environment = substr($arg, 6);
    } elseif ($arg === '--env' && isset($argv[$i + 1])) {
        $environment = $argv[$i + 1];
    } elseif (strpos($arg, '-e=') === 0) {
        $environment = substr($arg, 3);
    } elseif ($arg === '-e' && isset($argv[$i + 1])) {
        $environment = $argv[$i + 1];
    } elseif (strpos($arg, '-') !== 0) {
        $mode = $arg;
    }
}

// ---- 启动 Server ----
$app = Framework::getInstance($environment);
$app->run($mode);
