<?php

namespace App\Services\Trader;

use App\Services\Trader\ExitRules\ExitRules;
use App\Services\Trader\Fee\FeeCalculator;
use App\Services\Trader\Fee\SlippageCalculator;
use App\Services\Trader\Market\ArrayDataProvider;
use App\Services\Trader\Market\DataProviderInterface;
use App\Services\Trader\Model\Wallet;
use App\Services\Trader\Protection\ProtectionManager;
use App\Services\Trader\Strategy\IndicatorCalculator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sikelan\Core\Config;

/**
 * 回测 / Trader 服务工厂（ServiceProvider / Factory）
 *
 * 作用：
 *   - 读取 config/trader.php 配置
 *   - 创建 Wallet / MatchingEngine / ProtectionManager / ExitRules 等基础组件
 *   - 统一 new Backtesting()（这样业务代码不用关心底层组件怎么拼装）
 *   - 为 CLI 命令（trader:backtest）、Controller、Task 都可以复用同一套组装
 *
 * 设计：不直接注册到全局容器（避免容器里多实例冲突），而是以 Facade 形式暴露静态方法：
 *   BacktestServiceProvider::newBacktesting($container, $dataProvider, $strategy)
 *
 * 也可以直接 new BacktestServiceProvider($config, $logger) 组装，方便单元测试独立实例。
 */
class BacktestServiceProvider
{
    /**
     * 面向业务的主 API：从容器拿到 config + logger，按配置装配出 Backtesting 实例
     *
     * @param ContainerInterface     $container
     * @param DataProviderInterface  $dataProvider  回测数据来源
     * @param Strategy\StrategyInterface $strategy  策略实例
     * @param array                  $overrides     覆盖配置（可覆盖 stake_currency / warmup_candles 等）
     */
    public static function newBacktesting(
        ContainerInterface $container,
        DataProviderInterface $dataProvider,
        Strategy\StrategyInterface $strategy,
        array $overrides = []
    ): Backtesting {
        /** @var Config $config */
        $config = $container->has(Config::class) ? $container->get(Config::class) : null;
        /** @var LoggerInterface|null $logger */
        $logger = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;

        // 如果 logger 支持 withChannel（本框架 Logger），把交易所服务同通道迁移到 trader
        if ($logger !== null && method_exists($logger, 'withChannel')) {
            $channelName = 'trader';
            if ($config && ($c = $config->get('trader.log_channel'))) {
                $channelName = $c;
            }
            $logger = $logger->withChannel($channelName);
        }

        $traderCfg = $config ? ($config->get('trader') ?? []) : [];
        $traderCfg = array_replace($traderCfg, $overrides);

        return self::build($traderCfg, $dataProvider, $strategy, $logger);
    }

    /**
     * 纯配置装配（不依赖容器，单元测试用）
     *
     * @param array<string, mixed>   $traderCfg
     */
    public static function build(
        array $traderCfg,
        DataProviderInterface $dataProvider,
        Strategy\StrategyInterface $strategy,
        ?LoggerInterface $logger
    ): Backtesting {
        // ---- 硬依赖检查：所有策略指标统一使用 PHP trader 扩展计算 ----
        // 未安装请执行: pecl install trader
        IndicatorCalculator::requireTraderExtension();

        $stake = (string) ($traderCfg['stake_currency'] ?? 'USDT');

        // Wallet：初始化 stake currency 初始资金（一般是 USDT），其他币种 0
        $initial = (float) ($traderCfg['initial_capital'] ?? 10_000.0);
        $wallet  = new Wallet($stake);
        $wallet->setBalance($stake, $initial);

        // 手续费
        $feeCfg  = $traderCfg['fee'] ?? [];
        $feeCalc = new FeeCalculator(
            (float) ($feeCfg['maker_rate'] ?? 0.0002),
            (float) ($feeCfg['taker_rate'] ?? 0.0004)
        );

        // 滑点
        $slCfg  = $traderCfg['slippage'] ?? [];
        $slCalc = new SlippageCalculator(
            (float) ($slCfg['default_pct'] ?? 0.001),
            (array) ($slCfg['pair_overrides'] ?? [])
        );

        // 保护管理器
        $protCfg = $traderCfg['protection'] ?? [];
        $protection = new ProtectionManager(
            (array) ($protCfg['by_exit_reason'] ?? []),
            (int)   ($protCfg['default_cooling_ms'] ?? 0)
        );

        $engine     = new MatchingEngine($feeCalc, $slCalc, new ExitRules(), $stake);
        $warmupCand = (int) ($traderCfg['warmup_candles'] ?? 300);
        $runMode    = (string) ($traderCfg['run_mode']      ?? \App\Services\Trader\Enum\RunMode::BACKTEST);
        $trdMode    = (string) ($traderCfg['trading_mode']  ?? \App\Services\Trader\Enum\TradingMode::SPOT);

        return new Backtesting(
            $dataProvider,
            $strategy,
            $engine,
            $protection,
            $wallet,
            [
                'logger'         => $logger,
                'run_mode'       => $runMode,
                'trading_mode'   => $trdMode,
                'stake_currency' => $stake,
                'warmup_candles' => $warmupCand,
            ]
        );
    }

    /**
     * 创建空的 ArrayDataProvider（CLI 里最常用）
     */
    public static function createArrayDataProvider(): ArrayDataProvider
    {
        return new ArrayDataProvider();
    }

    // ============================================================================
    //  策略注册表 + 按别名创建策略（CLI/Controller 通过字符串名即可引用策略）
    // ============================================================================

    /**
     * 从配置 trader.strategies 读取策略别名 → 类名映射。
     * 形如：
     *   'strategies' => [
     *       'EmaCross' => ['class' => EmaCrossStrategy::class,
     *                      'construct' => [20, 50, 0.003]],
     *       'MeanRev'  => ['class' => BollingerRsiMeanReversionStrategy::class,
     *                      'construct' => [20, 2.0, 14, 30.0, 65.0, 0.8]],
     *   ];
     *
     *   也支持简化形式（无构造参数或使用默认构造参数）：
     *       'EmaCross' => EmaCrossStrategy::class,
     *
     * @param Config|array<string, mixed> $configOrArray Config 实例或 trader.php 配置数组
     * @return array<string, array{class:class-string<Strategy\StrategyInterface>, construct: array<int, mixed>}>
     */
    public static function getStrategyRegistry($configOrArray): array
    {
        if ($configOrArray instanceof Config) {
            $traderCfg = (array) ($configOrArray->get('trader') ?? []);
        } elseif (is_array($configOrArray)) {
            $traderCfg = $configOrArray;
        } else {
            throw new \InvalidArgumentException(
                'configOrArray must be Config instance or config array'
            );
        }
        $raw = (array) ($traderCfg['strategies'] ?? []);
        $normalized = [];
        foreach ($raw as $alias => $entry) {
            $class = null;
            $constructArgs = [];
            if (is_string($entry)) {
                $class = $entry;
            } elseif (is_array($entry)) {
                $class = $entry['class'] ?? null;
                $constructArgs = array_values($entry['construct'] ?? []);
            }
            if (!is_string($class) || !is_a($class, Strategy\StrategyInterface::class, true)) {
                throw new \InvalidArgumentException(
                    "策略别名 {$alias} 对应的 class 无效（必须实现 StrategyInterface）: "
                    . var_export($class, true)
                );
            }
            $normalized[(string) $alias] = [
                'class'     => $class,
                'construct' => $constructArgs,
            ];
        }
        return $normalized;
    }

    /**
     * 用策略别名创建策略实例。
     *
     *   $strategy = BacktestServiceProvider::createStrategyByName($container, 'MeanRev');
     *
     * 如果 config 里找不到别名，会退化为直接把 $name 当成类名尝试（例如传入完整类名）。
     *
     * @param ContainerInterface|array<string, mixed> $configOrContainer 容器或 trader.php 配置数组
     * @param string                                  $name            策略别名（例 'BollingerMeanRev'）或完整类名
     * @param array<int, mixed>                       $overrideConstruct  覆盖构造参数（可选）
     */
    public static function createStrategyByName($configOrContainer, string $name, array $overrideConstruct = []): Strategy\StrategyInterface
    {
        $registry = [];
        if ($configOrContainer instanceof ContainerInterface) {
            $config = $configOrContainer->has(Config::class) ? $configOrContainer->get(Config::class) : null;
            $registry = $config ? self::getStrategyRegistry($config) : [];
        } elseif (is_array($configOrContainer)) {
            $registry = self::getStrategyRegistry($configOrContainer);
        }

        if (isset($registry[$name])) {
            $entry = $registry[$name];
            $class = $entry['class'];
            $args = $overrideConstruct !== [] ? $overrideConstruct : $entry['construct'];
            return self::constructStrategy($class, $args);
        }

        // 退化：直接把 $name 当类名（没注册但能找到，比如用户直接传类名，不用改 config）
        if (class_exists($name) && is_a($name, Strategy\StrategyInterface::class, true)) {
            return self::constructStrategy($name, $overrideConstruct);
        }
        throw new \InvalidArgumentException(
            "策略 '{$name}' 未找到。请在 config/trader.php 的 strategies 里注册，或使用完整类名。"
            . (PHP_EOL . "当前已注册策略: [" . implode(', ', array_keys($registry)) . "]")
        );
    }

    /**
     * 反射 + 构造参数实例化策略。单独抽出来便于以后加入 DI 自动 resolve。
     *
     * @param class-string<Strategy\StrategyInterface> $class
     * @param array<int, mixed>                        $args
     */
    private static function constructStrategy(string $class, array $args): Strategy\StrategyInterface
    {
        if ($args === []) {
            return new $class();
        }
        // new $class(...$args) 参数展开
        return new $class(...$args);
    }

    /**
     * 便捷入口：按别名 + DataProvider + 配置，一步装配出 Backtesting。
     *
     *   $backtest = BacktestServiceProvider::newBacktestingByName(
     *       container(),
     *       $dp,
     *       'BollingerMeanRev'
     *   );
     *
     * @param ContainerInterface    $container
     * @param DataProviderInterface $dataProvider
     * @param string                $strategyName 别名或完整类名
     * @param array                 $backtestOverrides 覆盖 trader.php 顶层配置
     * @param array<int, mixed>     $strategyConstructArgs 覆盖策略构造参数
     */
    public static function newBacktestingByName(
        ContainerInterface $container,
        DataProviderInterface $dataProvider,
        string $strategyName,
        array $backtestOverrides = [],
        array $strategyConstructArgs = []
    ): Backtesting {
        $strategy = self::createStrategyByName($container, $strategyName, $strategyConstructArgs);
        return self::newBacktesting($container, $dataProvider, $strategy, $backtestOverrides);
    }
}
