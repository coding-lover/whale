<?php

namespace App\Services\Trader;

use App\Services\Exchanges\TradingSymbol;
use App\Services\Trader\ExitRules\ExitRules;
use App\Services\Trader\Fee\FeeCalculator;
use App\Services\Trader\Fee\SlippageCalculator;
use App\Services\Trader\Market\ArrayDataProvider;
use App\Services\Trader\Market\Candle;
use App\Services\Trader\Market\DataProviderInterface;
use App\Services\Trader\Market\KlinesCsvReader;
use App\Services\Trader\Market\KlinesCsvWriter;
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
    //  CSV → ArrayDataProvider 便捷入口（一键加载 / 自动下载 / 多交易对批量）
    // ============================================================================

    /**
     * 极简入口（单交易对）：传 exchange / symbol / timeframe，自动从 runtime/trader/data 找 CSV → 读 → 塞 ArrayDataProvider。
     *
     * 找不到 CSV 时的行为：
     *   ① 传了 $downloadOptions → 自动调 TraderDownloadKlinesCommand::download() 下载
     *   ② 没传 → 抛异常带手动下载指引
     *
     * 多交易对请用 loadDataProviderBatch()。
     *
     * @param string $exchange      交易所名（binance/okx，小写）
     * @param string $symbol        标准交易对（BTC/USDT / BTC/USDT:SWAP …）
     * @param string $timeframe     K 线周期（1m/5m/15m/1h/4h/1d/1w）
     * @param array<string, mixed> $downloadOptions  找不到 CSV 时自动下载的参数。支持：days / from / to / retries / retry-base / dry-run
     * @param bool $allowGaps  strict K 线间隔校验（默认 false）
     *
     * @throws \RuntimeException 文件缺失且无 $downloadOptions / 下载失败 / CSV 损坏
     */
    public static function loadDataProvider(
        string $exchange,
        string $symbol,
        string $timeframe,
        array $downloadOptions = [],
        bool $allowGaps = false
    ): ArrayDataProvider {
        $pairs = [['symbol' => $symbol]];
        return self::loadDataProviderBatch($exchange, $timeframe, $pairs, $downloadOptions, $allowGaps);
    }

    /**
     * 多交易对批量加载：一次把多个 symbol 的 CSV 塞进**同一个** ArrayDataProvider。
     *
     * 适用场景：Backtesting::run([...多个 symbol], $timeframe) 的前置数据准备。
     *
     * 找不到 CSV 时的行为与 loadDataProvider 相同——传了 $downloadOptions 就自动下载。
     * 批量场景下**每个 symbol 独立判断是否需要下载**，互不阻塞。
     *
     *   // 批量加载：显式指定每个 pair 的覆盖参数
     *   $dp = BacktestServiceProvider::loadDataProviderBatch('binance', '1h', [
     *       ['symbol' => 'BTC/USDT'],
     *       ['symbol' => 'ETH/USDT', 'download' => ['days' => 30]],     // 覆盖全局 downloadOptions
     *       ['symbol' => 'BTC/USDT:SWAP', 'allowGaps' => true],         // 覆盖全局 allowGaps
     *   ], ['days' => 7]);
     *
     *   // 简写：每个 pair 只有 symbol
     *   $dp = BacktestServiceProvider::loadDataProviderBatch('binance', '1h', [
     *       ['symbol' => 'BTC/USDT'],
     *       ['symbol' => 'ETH/USDT'],
     *       ['symbol' => 'BNB/USDT'],
     *   ], ['days' => 7]);
     *
     * @param string                       $exchange         交易所名（统一前缀）
     * @param string                       $timeframe        K 线周期（统一）
     * @param list<array{symbol:string, download?:array<string,mixed>, allowGaps?:bool}> $pairs 每个 pair 的配置
     * @param array<string, mixed>         $downloadOptions  全局默认下载参数（pair 级 download 会覆盖）
     * @param bool                         $allowGaps        全局默认 allowGaps（pair 级 allowGaps 会覆盖）
     *
     * @throws \RuntimeException 某个 pair 加载失败时直接抛出（带 pair 信息），已加载的不会回滚
     */
    public static function loadDataProviderBatch(
        string $exchange,
        string $timeframe,
        array $pairs,
        array $downloadOptions = [],
        bool $allowGaps = false
    ): ArrayDataProvider {
        $exchange = strtolower($exchange);
        $dp = new ArrayDataProvider();
        $loadedCount = 0;
        $errors = [];

        foreach ($pairs as $i => $pair) {
            $symbol = $pair['symbol'] ?? null;
            if (!is_string($symbol) || $symbol === '') {
                $errors[] = "pairs[$i]: symbol 必须是非空字符串";
                continue;
            }

            // pair 级覆盖
            $pairDownload = (array) ($pair['download'] ?? $downloadOptions);
            $pairAllowGaps = (bool) ($pair['allowGaps'] ?? $allowGaps);

            try {
                [$symbolObj, $candles] = self::loadOrDownloadCandles(
                    $exchange, $symbol, $timeframe, $pairDownload
                );
                $dp->setCandles($symbolObj, $timeframe, $candles, $pairAllowGaps);
                $loadedCount++;
            } catch (\Throwable $e) {
                $errors[] = "pairs[$i] {$symbol}: " . $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new \RuntimeException(
                "[loadDataProviderBatch] 完成 {$loadedCount}/" . count($pairs) . "，错误：\n"
                . implode("\n", array_map(fn($e) => "  • {$e}", $errors))
            );
        }

        return $dp;
    }

    /**
     * 纯函数：算出某个 CSV 的绝对路径。
     *
     * @return array{csvPath:string, filename:string}  路径 + 文件名（分别返回便于单测断言）
     */
    private static function resolveCsvPath(string $exchange, string $symbol, string $timeframe): array
    {
        $dataDir = defined('RUNTIME_PATH')
            ? RUNTIME_PATH . '/trader/data'
            : dirname(__DIR__, 3) . '/app/runtime/trader/data';
        $filename = (new KlinesCsvWriter())->buildFilename($symbol, $timeframe);
        $csvPath  = $dataDir . DIRECTORY_SEPARATOR . strtolower($exchange) . DIRECTORY_SEPARATOR . $filename;
        return ['csvPath' => $csvPath, 'filename' => $filename];
    }

    /**
     * 单个 pair 的"存在→读 / 不存在→下载→再读"主逻辑。
     *
     * @return array{0:TradingSymbol, 1:Candle[]}  symbol 解析后的对象 + K 线数组
     * @throws \RuntimeException 文件缺失且无 $downloadOptions / 下载失败 / CSV 损坏
     */
    private static function loadOrDownloadCandles(
        string $exchange,
        string $symbol,
        string $timeframe,
        array $downloadOptions
    ): array {
        $symbolObj = TradingSymbol::parse($symbol);
        ['csvPath' => $csvPath] = self::resolveCsvPath($exchange, $symbol, $timeframe);

        // 1. 存在 → 直接读
        if (is_file($csvPath)) {
            return [$symbolObj, (new KlinesCsvReader())->read($csvPath)];
        }

        // 2. 不存在 + 有 downloadOptions（非 dry-run）→ 自动下载
        if ($downloadOptions !== [] && empty($downloadOptions['dry_run'])) {
            self::downloadKlines($exchange, $symbol, $timeframe, $downloadOptions);
            if (!is_file($csvPath)) {
                throw new \RuntimeException(
                    "下载完成但 CSV 仍未找到，路径 {$csvPath}。请检查 exchange/symbol/timeframe 是否合法。"
                );
            }
            return [$symbolObj, (new KlinesCsvReader())->read($csvPath)];
        }

        // 3. 不存在 + （没给 downloadOptions 或带了 dry-run）→ 抛异常带手动下载指引
        throw new \RuntimeException(self::buildMissingFileHint($csvPath, $exchange, $symbol, $timeframe));
    }

    /**
     * 调 TraderDownloadKlinesCommand::download() —— 把 exchange/symbol/timeframe/days 拼成完整参数。
     */
    private static function downloadKlines(
        string $exchange,
        string $symbol,
        string $timeframe,
        array $options
    ): void {
        if (!class_exists(\App\Commands\TraderDownloadKlinesCommand::class)) {
            throw new \RuntimeException(
                'TraderDownloadKlinesCommand 未加载。请确保 Composer autoload 正确。'
            );
        }
        $merged = array_merge([
            'exchange' => $exchange,
            'symbol'   => $symbol,
            'interval' => $timeframe,
            'days'     => 7,
        ], $options);

        echo "\033[33m[loadDataProvider]\033[0m CSV 不存在，自动下载 {$exchange} · {$symbol} · {$timeframe} ...\n";

        try {
            \App\Commands\TraderDownloadKlinesCommand::download($merged);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "自动下载 K 线失败：" . $e->getMessage()
                . "（请手动执行：php bin/sikelan trader:download-klines --exchange={$exchange} --symbol={$symbol} --interval={$timeframe}"
                . (isset($options['from']) ? " --from={$options['from']}" : '')
                . (isset($options['to'])   ? " --to={$options['to']}"   : '')
                . (isset($options['days']) ? " --days={$options['days']}" : '')
                . "）",
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * 文件缺失时生成的可读异常消息。
     */
    private static function buildMissingFileHint(
        string $csvPath,
        string $exchange,
        string $symbol,
        string $timeframe
    ): string {
        return <<<MSG
[loadDataProvider] CSV 不存在：{$csvPath}

请任选一种方式解决：

  ① 手动下载（推荐，最灵活）：
     php bin/sikelan trader:download-klines \\
       --exchange={$exchange} --symbol={$symbol} --interval={$timeframe} --days=7

  ② 调用时传入 downloadOptions 让它自动下载：
     \$dp = BacktestServiceProvider::loadDataProvider('{$exchange}', '{$symbol}', '{$timeframe}', ['days' => 7]);

MSG;
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
