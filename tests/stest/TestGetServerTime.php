<?php

/**
 * 真实 API 调用测试 - 获取 Binance 服务器时间
 *
 * 通过代理 127.0.0.1:6666 访问 Binance /api/v3/time 接口
 * 使用 Swoole 协程 HTTP 客户端
 *
 * 运行方式：
 *   php tests/stest/TestGetServerTime.php
 */

// 1. 加载自动加载器
require_once __DIR__ . '/../../vendor/autoload.php';

// 2. 加载框架常量和公共函数
require_once __DIR__ . '/../../sikelan/Core/constants.php';
require_once __DIR__ . '/../../sikelan/Core/common.php';

use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use App\Services\Exchanges\ExchangeManager;

echo "========================================\n";
echo "Binance getServerTime 真实调用测试\n";
echo "========================================\n\n";

// 3. 加载配置
$config = new Config();
$configPath = BASE_PATH . '/config/exchanges.php';
$exchangeConfig = require $configPath;

// 合并到 Config 实例
foreach ($exchangeConfig as $key => $value) {
    $config->set('exchanges.' . $key, $value);
}

echo "[配置] 已加载 exchanges.php\n";
echo "[配置] 默认交易所: " . $config->get('exchanges.default', 'N/A') . "\n";
echo "[配置] 代理: " . $config->get('exchanges.proxy.host', 'N/A') . ":" . $config->get('exchanges.proxy.port', 'N/A') . "\n";
echo "[配置] 代理启用: " . ($config->get('exchanges.proxy.enabled', true) ? '是' : '否') . "\n";
echo "[配置] SSL验证: " . ($config->get('exchanges.binance.ssl_verify', true) ? '启用' : '关闭') . "\n\n";

// 4. 创建日志实例（输出到控制台）
$logger = new Logger($config);

// 5. 创建 ExchangeManager
$manager = new ExchangeManager($config, $logger);

// 启用调试日志以查看请求详情
$manager->enableDebugLog();

echo "[ExchangeManager] 已创建\n";
echo "[ExchangeManager] 已注册交易所: " . implode(', ', $manager->getRegisteredExchanges()) . "\n";
echo "[ExchangeManager] 活跃交易所: " . implode(', ', $manager->getActiveExchanges()) . "\n\n";

// 6. 在 Swoole 协程中调用 getServerTime
$exception = null;
$startTime = microtime(true);

\Swoole\Coroutine\run(function () use ($manager, &$exception) {
    try {
        echo "[协程] 开始调用 getServerTime...\n";

        // 方式1：通过 ExchangeManager 默认交易所调用
        $time = $manager->getServerTime();

        echo "[成功] Binance 服务器时间戳: {$time}\n";

        // 验证时间合理性
        $nowMs = (int) (microtime(true) * 1000);
        $diff = abs($nowMs - $time);
        echo "[验证] 本地时间戳: {$nowMs}\n";
        echo "[验证] 时间差: {$diff}ms (" . round($diff / 1000, 2) . "秒)\n";

        if ($diff < 60000) {
            echo "[验证] ✓ 时间差在合理范围内（< 60秒）\n";
        } else {
            echo "[验证] ✗ 时间差过大，可能存在时钟同步问题\n";
        }

        // 方式2：直接通过 exchange() 调用
        echo "\n[测试] 通过 exchange('binance') 直接调用...\n";
        $time2 = $manager->exchange('binance')->getServerTime();
        echo "[成功] 直接调用获取时间戳: {$time2}\n";

    } catch (\Throwable $e) {
        $exception = $e;
    }
});

$elapsed = round((microtime(true) - $startTime) * 1000, 2);

echo "\n========================================\n";

// 7. 检查日志文件
$logFile = LOG_PATH . '/exchange-service_' . date('Y-m-d') . '.log';
echo "[日志] 检查 exchange-service 日志文件...\n";
if (file_exists($logFile)) {
    $logSize = filesize($logFile);
    echo "[日志] ✓ 日志文件存在: {$logFile}\n";
    echo "[日志] 文件大小: {$logSize} bytes\n";
    
    // 读取最后几行日志
    $lines = file($logFile);
    $lastLines = array_slice($lines, -5);
    echo "[日志] 最近 5 条日志:\n";
    foreach ($lastLines as $line) {
        echo "  " . trim($line) . "\n";
    }
} else {
    echo "[日志] ✗ 日志文件不存在: {$logFile}\n";
}

echo "\n";
if ($exception) {
    echo "[失败] 测试异常: " . get_class($exception) . "\n";
    echo "[错误信息] " . $exception->getMessage() . "\n";
    echo "[文件] " . $exception->getFile() . ":" . $exception->getLine() . "\n";
    echo "[堆栈]\n" . $exception->getTraceAsString() . "\n";
    exit(1);
} else {
    echo "[成功] 测试完成！总耗时: {$elapsed}ms\n";
}
echo "========================================\n";