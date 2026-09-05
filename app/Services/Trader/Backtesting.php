<?php

namespace App\Services\Trader;

use App\Services\Exchanges\TradingSymbol;
use App\Services\Trader\Enum\ExitType;
use App\Services\Trader\Enum\RunMode;
use App\Services\Trader\Enum\TradingMode;
use App\Services\Trader\Market\DataProviderInterface;
use App\Services\Trader\Market\Timeframe;
use App\Services\Trader\Model\TradeRecord;
use App\Services\Trader\Model\Wallet;
use App\Services\Trader\Model\WalletSnapshot;
use App\Services\Trader\Protection\ProtectionManager;
use App\Services\Trader\Strategy\SignalCols;
use App\Services\Trader\Strategy\StrategyInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

/**
 * 回测编排器（Backtesting）
 *
 * 对应 Freqtrade `Backtesting` 大类。主流程：
 *  1. 加载数据（DataProviderInterface，注入进来，可替换）
 *  2. 跑 strategy 的三步法：populateIndicators / populateEntryTrend / populateExitTrend
 *     → 结果是一个 12+ 列的矩阵（list<list>，按列下标访问，避免 assoc 哈希开销）
 *  3. 对每个 pair，按 K 线顺序推进：
 *     - 对每一根 row[i] 信号做：
 *       a) 先检查所有 open trades 的 exit（优先级最高：平仓永远优先于开新仓）
 *       b) 更新 trailing 最高/低价
 *       c) ProtectionManager 检查准入
 *       d) 如果有信号 → MatchingEngine.executeEntry()（默认在 row[i+1] 成交，防止前视偏差）
 *  4. 每根 K 线结束后：Wallet 打快照（为 PerformanceReport 和绘图准备）
 *  5. 全部结束 → PerformanceReport 算指标
 *
 * 本类是纯 orchestrator：不实现任何具体算法，算法都委托给
 *   - ExitRules（平仓规则匹配）
 *   - MatchingEngine（撮合价 / 手续费 / 钱包变动）
 *   - ProtectionManager（准入校验 / PairLock）
 *   - DataProviderInterface（数据读取）
 */
class Backtesting implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var DataProviderInterface */
    private $dataProvider;

    /** @var StrategyInterface */
    private $strategy;

    /** @var MatchingEngine */
    private $matching;

    /** @var ProtectionManager */
    private $protection;

    /** @var Wallet */
    private $wallet;

    /** @var string RunMode（BACKTEST） */
    private $runMode;

    /** @var TradingMode */
    private $tradingMode;

    /** @var string stake 货币（默认 USDT） */
    private $stakeCurrency;

    /** @var int 预热 K 线数（填充指标所需，如 RSI 14 根 + EMA200，默认 300）*/
    private $warmupCandles;

    // ---------- 运行时状态 ----------
    /** @var TradeRecord[] 所有已平 + 未平的持仓 */
    private $allTrades = [];
    /** @var array<int, TradeRecord> trade_id => 未平仓 Trade */
    private $openTrades = [];
    /** @var WalletSnapshot[] 每根 K 线结束后的钱包快照 */
    private $equitySnapshots = [];
    /** @var int 信号被 ProtectionManager 拒绝计数 */
    private $rejectedSignals = 0;
    /** @var int 入场信号计数（含被拒 + 成功）*/
    private $signalsTotal = 0;

    public function __construct(
        DataProviderInterface $dataProvider,
        StrategyInterface $strategy,
        MatchingEngine $matching,
        ProtectionManager $protection,
        Wallet $wallet,
        array $options = []
    ) {
        $this->dataProvider = $dataProvider;
        $this->strategy     = $strategy;
        $this->matching     = $matching;
        $this->protection   = $protection;
        $this->wallet       = $wallet;
        $this->logger       = $options['logger'] ?? new NullLogger();
        $this->runMode      = $options['run_mode']      ?? RunMode::BACKTEST;
        $this->tradingMode  = $options['trading_mode']  ?? TradingMode::SPOT;
        $this->stakeCurrency = $options['stake_currency'] ?? 'USDT';
        $this->warmupCandles = (int) ($options['warmup_candles'] ?? 300);
    }

    /**
     * 主入口：对一组 symbol 执行回测
     *
     * 参数全部可选——省略时自动从注入的 DataProvider 推导，避免"加载时声明一遍、
     * 运行时再传一遍"的冗余：
     *   - $symbols 为 null   → 取 dataProvider->getAvailableSymbols()（全部已加载交易对）
     *   - $timeframe 为 null → 取 dataProvider->getAvailableTimeframes()：
     *       · 仅 1 个周期 → 自动使用
     *       · 0 个周期   → 抛异常（provider 为空）
     *       · 多个周期   → 抛异常（歧义，必须显式指定）
     *
     * 显式传参仍完全支持（向后兼容），例如只想回测已加载数据中的某几个交易对。
     *
     * @param TradingSymbol[]|null $symbols   要回测的交易对；null = 全部已加载
     * @param string|null          $timeframe Timeframe::TF_*；null = 自动推导
     * @param int|null             $fromMs    起始毫秒（可选）
     * @param int|null             $toMs      结束毫秒（可选）
     * @return BacktestResult
     */
    public function run(?array $symbols = null, ?string $timeframe = null, ?int $fromMs = null, ?int $toMs = null): BacktestResult
    {
        // ---- 自动推导交易对：未传则用 DataProvider 中全部已加载交易对 ----
        if ($symbols === null) {
            $symbols = $this->dataProvider->getAvailableSymbols();
        }
        if ($symbols === []) {
            throw new \InvalidArgumentException(
                'Backtesting::run needs at least one symbol (none passed and none loaded in DataProvider)'
            );
        }

        // ---- 自动推导周期：未传则要求 DataProvider 中恰好只有 1 个周期 ----
        if ($timeframe === null) {
            $timeframe = $this->resolveTimeframe();
        }
        if (!Timeframe::isValid($timeframe)) {
            throw new \InvalidArgumentException("Invalid timeframe: {$timeframe}");
        }
        $barMs = Timeframe::toMilliseconds($timeframe);

        $this->resetRunState();

        // 1. 对每个 symbol：加载 K 线 + 计算策略信号矩阵
        /** @var array<string, array<string, mixed>> $prepared key = (string)symbol */
        $prepared = [];
        $globalEndRow = 0;
        foreach ($symbols as $symbol) {
            $candles = $this->dataProvider->getCandles($symbol, $timeframe, $fromMs, $toMs);
            if (count($candles) <= $this->warmupCandles) {
                $this->logger && $this->logger->warning(
                    "[trader] Not enough candles for {$symbol} (" . count($candles) . " <= warmup {$this->warmupCandles})"
                );
                continue;
            }
            $matrix0  = SignalCols::candlesToMatrix($candles);
            $matrix1  = $this->strategy->populateIndicators($matrix0, $symbol, $timeframe);
            $matrix2  = $this->strategy->populateEntryTrend($matrix1);
            $matrix   = $this->strategy->populateExitTrend($matrix2);
            $prepared[(string) $symbol] = [
                'symbol' => $symbol,
                'candles' => $candles,
                'matrix'  => $matrix,
            ];
            $globalEndRow = max($globalEndRow, count($matrix));
        }
        if ($prepared === []) {
            throw new \RuntimeException('[trader] All symbols failed warmup. Nothing to backtest.');
        }

        // 2. 主循环：逐行推进（统一行号遍历，解决 A pair 缺 B pair 没缺的情况也能对齐时间轴）
        $startIdx = $this->warmupCandles;
        for ($rowI = $startIdx; $rowI < $globalEndRow; $rowI++) {
            $rowTs = null;
            $quotePrices = []; // 每个 pair 结束后把 close 记录下来，用于 stake 折算快照
            foreach ($prepared as $symStr => $p) {
                /** @var TradingSymbol $symbol */
                $symbol = $p['symbol'];
                $matrix = $p['matrix'];
                if ($rowI >= count($matrix)) {
                    continue;
                }
                $row = $matrix[$rowI];
                $rowTs = (int) $row[SignalCols::DATE];
                $prevRow = $rowI > 0 ? $matrix[$rowI - 1] : $row;

                // (a) 先处理所有 open trade 的平仓（对这个 pair）
                $this->processExitsForPair($symbol, $row, $rowI, $barMs, $prevRow);
                // (b) 更新 trailing stop 的极值（无论平没平都更新已在 (a) 前？这里在 exit 前更新会更精确）
                // → 实际 processExitsForPair 内部先 updateExtremesAndTrailing

                // (c) 更新 quote price（用于快照）
                $quotePrices[$symbol->getBase()] = (float) $row[SignalCols::CLOSE];

                // (d) 入场信号：executeEntry 必须用 row[i+1] 的 open 成交（防前视偏差）
                $this->processEntriesForPair($symbol, $row, $rowI, $matrix);
            }

            // 3. 每个 row 结束后：ProtectionManager 清理过期锁
            if ($rowTs !== null && ($rowI & 127) === 0) {
                // 每 128 根 prune 一次，降低循环开销
                $this->protection->pruneExpired($rowTs);
            }

            // 4. 钱包快照（每个 row 一条；rowTs 最后一个 pair 的 rowTs ≈ 全局一致）
            if ($rowTs !== null) {
                $this->equitySnapshots[] = $this->wallet->snapshot($rowTs, $quotePrices);
            }
        }

        // 5. 强制平掉所有未平仓（回测时间到）
        $this->forceCloseAllOpenTrades(
            $prepared,
            $globalEndRow - 1,
            $fromMs ?? 0,
            $barMs
        );

        return new BacktestResult(
            $this->strategy->getName(),
            $this->strategy->getVersion(),
            (string) $this->runMode,
            (string) $this->tradingMode,
            $this->stakeCurrency,
            $timeframe,
            array_map(static function (TradeRecord $t) { return $t->toArray(); }, $this->allTrades),
            array_map(static function (WalletSnapshot $s) { return $s->toArray(); }, $this->equitySnapshots),
            $this->signalsTotal,
            $this->rejectedSignals,
            $this->wallet->getTotal($this->stakeCurrency)
        );
    }

    // -----------------------------------------------------------
    //  内层：处理平仓 + 入场
    // -----------------------------------------------------------

    /**
     * 处理指定 pair 的所有 open trade：检查是否要平仓
     */
    private function processExitsForPair(
        TradingSymbol $symbol,
        array $row,
        int $rowI,
        int $barMs,
        array $prevRow
    ): void {
        unset($prevRow); // 暂时不用，保留签名方便扩展
        $symStr = (string) $symbol;

        foreach ($this->openTrades as $tradeId => $trade) {
            /** @var TradeRecord $trade */
            if ((string) $trade->getSymbol() !== $symStr) {
                continue;
            }

            // (i) 更新极值 + trailing stop 价（先于 exit 检查：必须保证用这根 K 线的高低价）
            $high  = (float) $row[SignalCols::HIGH];
            $low   = (float) $row[SignalCols::LOW];
            $close = (float) $row[SignalCols::CLOSE];
            $trade->updateExtremesAndTrailing($high, $low, $close, $this->strategy->getTrailingStop() > 0
                ? $this->strategy->getTrailingStop()
                : null);

            // (ii) 计算 exit_signal 是否触发（根据 long/short 选对应列）
            list($ei_long, $xi_long, $ei_short, $xi_short) = $this->strategy->getSignalColumnIndexes();
            $exitSignal = false;
            $exitTag    = '';
            if ($trade->isLong()) {
                if (!empty($row[$xi_long])) {
                    $exitSignal = true;
                    $exitTag    = (string) ($row[SignalCols::EXIT_TAG] ?? '');
                }
            } else {
                if (!empty($row[$xi_short])) {
                    $exitSignal = true;
                    $exitTag    = (string) ($row[SignalCols::EXIT_TAG] ?? '');
                }
            }

            // (iii) MatchingEngine 综合检查
            $hit = $this->matching->checkTradeExit(
                $trade,
                $row,
                $barMs,
                $this->strategy,
                $exitSignal,
                $exitTag,
                $rowI
            );
            if ($hit !== null) {
                list($exitType, $triggerPrice, $eTag) = $hit;
                $this->matching->executeExit($trade, $row, $exitType, $triggerPrice, $eTag, $this->wallet);

                if ($trade->isClosed()) {
                    // ProtectionManager 加入冷却锁
                    $this->protection->lockAfterExit($trade, (int) $row[SignalCols::DATE]);
                    unset($this->openTrades[$tradeId]);
                }
            }
        }
    }

    /**
     * 处理指定 pair 的入场信号
     */
    private function processEntriesForPair(TradingSymbol $symbol, array $row, int $rowI, array $matrix): void
    {
        list($ei_long, , $ei_short, ) = $this->strategy->getSignalColumnIndexes();
        $nowTs = (int) $row[SignalCols::DATE];

        $signals = []; // 方向 → sigStrength
        if (!empty($row[$ei_long]) && $this->strategy->canLong()) {
            $signals['long']  = (int) $row[$ei_long];
        }
        if (!empty($row[$ei_short]) && $this->strategy->canShort()) {
            $signals['short'] = (int) $row[$ei_short];
        }

        foreach ($signals as $direction => $strength) {
            $this->signalsTotal++;
            $forceEntry = $strength === SignalCols::SIG_FORCE;
            $openTradesArr = array_values($this->openTrades);

            $violation = $this->protection->checkEntryAllowed(
                $openTradesArr,
                $symbol,
                $this->strategy,
                $nowTs,
                $forceEntry
            );
            if ($violation !== null) {
                $this->rejectedSignals++;
                $this->logger && $this->logger->debug('[trader] Entry rejected: ' . $violation);
                continue;
            }

            // 必须在 row[i+1] 成交 → 防止"用 close 价发现信号然后同一根买"的前视偏差
            if ($rowI + 1 >= count($matrix)) {
                break; // 最后一根无法再执行，跳过
            }
            $enterTag = (string) ($row[SignalCols::ENTER_TAG] ?? '');
            $execRow  = $matrix[$rowI + 1];

            $trade = $this->matching->executeEntry(
                $symbol,
                $direction,
                $this->strategy,
                $row,
                $execRow,
                $this->wallet,
                $this->tradingMode,
                $enterTag
            );
            if ($trade !== null) {
                $this->allTrades[]   = $trade;
                $this->openTrades[$trade->getId()] = $trade;
                $this->logger && $this->logger->debug(sprintf(
                    '[trader] OPEN #%d %s %s @ %.6f (stake %.2f)',
                    $trade->getId(),
                    $direction,
                    $symbol,
                    $trade->getOpenRate(),
                    $trade->getStakeAmount()
                ));
            }
        }
    }

    /**
     * 回测结束后：所有未平仓按最后一根 bar 的 close 强制平仓（exit = FORCE_EXIT）
     */
    private function forceCloseAllOpenTrades(array $prepared, int $lastRowI, int $fromMs, int $barMs): void
    {
        unset($fromMs); // 预留
        if ($this->openTrades === []) {
            return;
        }
        // 找到每个 trade 的 symbol 对应 matrix 的最后 row
        $lastRows = [];
        foreach ($prepared as $symStr => $p) {
            $m = $p['matrix'];
            $idx = min($lastRowI, count($m) - 1);
            if ($idx >= 0) {
                $lastRows[$symStr] = $m[$idx];
            }
        }
        foreach ($this->openTrades as $tradeId => $trade) {
            $symStr = (string) $trade->getSymbol();
            $row = $lastRows[$symStr] ?? null;
            if ($row === null) {
                continue;
            }
            // 先最后一次更新极值
            $trade->updateExtremesAndTrailing(
                (float) $row[SignalCols::HIGH],
                (float) $row[SignalCols::LOW],
                (float) $row[SignalCols::CLOSE],
                null
            );
            $triggerPrice = (float) $row[SignalCols::CLOSE];
            $this->matching->executeExit($trade, $row, ExitType::FORCE_EXIT, $triggerPrice, '', $this->wallet);
            unset($this->openTrades[$tradeId]);
        }
        unset($barMs);
    }

    private function resetRunState(): void
    {
        $this->allTrades       = [];
        $this->openTrades      = [];
        $this->equitySnapshots = [];
        $this->rejectedSignals = 0;
        $this->signalsTotal    = 0;
    }

    /**
     * 从 DataProvider 自动推导唯一回测周期
     *
     * 仅当 provider 中所有已加载数据都属于同一个周期时才可自动推导；
     * 为空或存在多个周期时抛异常，强制调用方显式指定（避免静默选错周期）。
     *
     * @return string Timeframe::TF_*
     * @throws \RuntimeException         provider 为空（没有任何已加载数据）
     * @throws \InvalidArgumentException provider 中存在多个周期（歧义）
     */
    private function resolveTimeframe(): string
    {
        $timeframes = $this->dataProvider->getAvailableTimeframes();
        if ($timeframes === []) {
            throw new \RuntimeException(
                '[trader] DataProvider 中没有任何已加载数据，无法自动推导 timeframe；请先加载数据或显式传入周期'
            );
        }
        if (count($timeframes) > 1) {
            throw new \InvalidArgumentException(
                '[trader] DataProvider 加载了多个周期（' . implode(', ', $timeframes)
                . '），无法自动决定回测周期；请在 run() 第二个参数显式指定'
            );
        }
        return $timeframes[0];
    }
}
