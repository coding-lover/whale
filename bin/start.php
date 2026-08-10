#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Sikelan\Framework;

if (extension_loaded('xdebug')) {
    @ini_set('xdebug.mode', 'off');
    @ini_set('xdebug.start_with_request', 'no');

    if (php_sapi_name() === 'cli') {
        echo "\033[33m⚠ Warning: Xdebug is loaded. It may cause issues with Swoole coroutines.\033[0m\n";
        echo "\033[33m  Please disable Xdebug in php.ini: set xdebug.mode=off\033[0m\n\n";
    }
}

$mode = 'http';
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

$app = Framework::getInstance($environment);

$app->run($mode);
