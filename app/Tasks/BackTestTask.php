<?php

namespace App\Tasks;

use App\Services\Trader\BacktestServiceProvider;
use App\Services\Trader\PerformanceReport;
use Sikelan\Task\TaskInterface;
use Sikelan\Core\Logger;

class BackTestTask implements TaskInterface
{
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function handle(array $args)
    {
        $this->logger->info('BackTestTask started', [
            'args' => json_encode($args),
        ]);

        // 1) 一行加载数据（CSV 不存在时自动下载最近 7 天）
        //$dp = BacktestServiceProvider::loadDataProvider('binance', 'BTC/USDT', '1h', ['days' => 30]);
        $dp = BacktestServiceProvider::loadDataProviderBatch(
            'binance',
            '15m',
            [
                ['symbol' => 'BTC/USDT'],
                ['symbol' => 'ETH/USDT']
            ],
            ['days' => 30]
        );

        // 1') 如果 CSV 不存在且想自动下载更多天数：
        //$dp = BacktestServiceProvider::loadDataProvider('binance', 'BTC/USDT', '1h', ['days' => 30]);

        // 2) 一行装配回测（用 config/trader.php 里的策略别名）
        $backtest = BacktestServiceProvider::newBacktestingByName(
            container(),          // 全局容器（自动取 Config + Logger）
            $dp,                   // 数据
            'MeanRevStd',          // 策略别名
            [
                'initial_capital'  => 100_000,  // 可选：覆盖 trader 顶层配置
                'warmup_candles'   => 60,
            ]
        );

        // 3) 运行：交易对和周期在上面 loadDataProviderBatch 已声明，这里零参数自动推导
        //    （等价于 run([BTC/USDT, ETH/USDT], '15m')，但不用重复写一遍）
        $result = $backtest->run();

        // 4) 看报表
        $perf = new PerformanceReport($result, 100_000, 365);
        echo '总收益率 ' . $perf->get('total_return_pct') . "%\n";
        echo '夏普比率 ' . $perf->get('sharpe_ratio') . "\n";
        echo '最大回撤 ' . $perf->get('max_drawdown_pct') . "%\n";
        echo '胜率     ' . $perf->get('win_rate_pct') . "%\n";

        return [
            'success' => true,
            'message' => '',
            'timestamp' => date('Y-m-d H:i:s'),
            'args_received' => $args
        ];
    }
}
