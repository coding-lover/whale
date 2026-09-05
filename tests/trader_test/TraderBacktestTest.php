<?php

namespace Sikelan\Tests\trader_test;

use App\Services\Exchanges\TradingSymbol;
use App\Services\Trader\Enum\ExitType;
use App\Services\Trader\Enum\OrderSide;
use App\Services\Trader\Enum\OrderType;
use App\Services\Trader\Enum\TradingMode;
use App\Services\Trader\ExitRules\ExitRules;
use App\Services\Trader\Fee\FeeCalculator;
use App\Services\Trader\Fee\SlippageCalculator;
use App\Services\Trader\MatchingEngine;
use App\Services\Trader\Market\Candle;
use App\Services\Trader\Model\TradeRecord;
use App\Services\Trader\Model\Wallet;
use App\Services\Trader\Protection\ProtectionManager;
use App\Services\Trader\Strategy\SignalCols;
use App\Services\Trader\Strategies\EmaCrossStrategy;
use App\Services\Trader\PerformanceReport;
use App\Services\Trader\BacktestServiceProvider;
use App\Services\Trader\Market\ArrayDataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Trader 回测系统核心单元测试
 *
 * 覆盖：
 *   1. Candle / Timeframe / ArrayDataProvider 数据层
 *   2. ExitRules：止损（long/short）、ROI 阶梯、HOLD 超时
 *   3. MatchingEngine.executeEntry 必须用 next-bar-open 入场
 *   4. MatchingEngine.executeExit 止损触发价必须使用 stopPrice（非 close）
 *   5. 手续费/滑点计算（maker vs taker）
 *   6. Wallet debit/credit/snapshot
 *   7. ProtectionManager + PairLock 冷却
 *   8. PerformanceReport：简单收益率/最大回撤/胜率
 *   9. E2E：用人工构造的 21 根上升/下跌 K 线跑 EmaCross 整个 Backtesting
 */
class TraderBacktestTest extends TestCase
{
    // ---- 1. Candle & DataProvider ----
    public function testCandleValidatesHighLow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Candle(1000, 100, 99, 95, 98, 10);  // high=99 < open=100
    }

    public function testArrayDataProviderRejectsOutOfOrderOrGap(): void
    {
        $symbol = TradingSymbol::parse('BTC/USDT');
        $dp = new ArrayDataProvider();

        // 乱序（后面的 ts 更小）
        $bad = [
            new Candle(1_700_000_000_000, 100, 102, 99, 101, 10),
            new Candle(1_699_999_999_000, 101, 102, 100, 102, 10),
        ];
        $this->expectException(\InvalidArgumentException::class);
        $dp->setCandles($symbol, '5m', $bad);
    }

    // ---- 2. ExitRules 止损 / ROI / HOLD ----

    public function testStopLossUsesStopPriceNotClose(): void
    {
        $rules = new ExitRules();
        $trade = $this->makeFakeLongTrade(100_000_000, 50_000.0); // open at 50k

        // close = 50,000（没动）但 low = 47,500 触及 3% 止损（48,500）
        $row = $this->makeRow(200_000_000, 50000, 50000, 47500, 50000, 10);
        $hit = $rules->check($trade, $row, 300_000, 0.03, [], 0, 0, 0);
        $this->assertNotNull($hit, '触及止损，应命中 ExitType::STOP_LOSS');
        $this->assertEquals(ExitType::STOP_LOSS, $hit[0]);
        // 触发价必须是 open × (1-3%) = 48500（不是 close 50000！）
        $this->assertEqualsWithDelta(48_500, $hit[1], 0.01);
    }

    public function testMinimalRoiTargetResolution(): void
    {
        $rules = new ExitRules();
        $table = [0 => 0.10, 30 => 0.05, 120 => 0.02, 240 => 0];
        $this->assertSame(0.10, $rules->resolveRoiTarget($table, 0));
        $this->assertSame(0.10, $rules->resolveRoiTarget($table, 10));
        $this->assertSame(0.05, $rules->resolveRoiTarget($table, 60));
        $this->assertSame(0.02, $rules->resolveRoiTarget($table, 150));
        $this->assertSame(0.0,  $rules->resolveRoiTarget($table, 500));
    }

    // ---- 3. MatchingEngine 入场：next-bar open ----

    public function testEntryUsesNextBarOpenPlusSlippage(): void
    {
        $engine = $this->makeEngine(
            $fee = new FeeCalculator(0.0, 0.0),      // 无手续费
            new SlippageCalculator(0.001)            // 0.1% 滑点
        );
        $wallet = new Wallet('USDT');
        $wallet->setBalance('USDT', 10_000.0);
        $strategy = new EmaCrossStrategy();
        $symbol = TradingSymbol::parse('BTC/USDT');

        // 信号 row = 100，执行 row = 101
        $entryRow = $this->makeRow(1_000, 50_000, 50_000, 50_000, 50_000, 10);
        $execRow  = $this->makeRow(2_000, 51_000, 52_000, 50_500, 51_500, 10);

        $trade = $engine->executeEntry(
            $symbol, 'long', $strategy, $entryRow, $execRow, $wallet, TradingMode::SPOT
        );

        $this->assertNotNull($trade);
        // 入场价 = next open × (1 + 0.1% slippage)
        $this->assertEqualsWithDelta(51_000 * 1.001, $trade->getOpenRate(), 0.01);
        // stake = 500 USDT，base amount = stake / fillPrice
        $this->assertEqualsWithDelta(500 / (51_000 * 1.001), $trade->getEntryAmount(), 1e-8);
    }

    // ---- 4. 止损成交用 stopPrice 而不是 high/low（撮合价夹逼）----

    public function testStopLossExecutionPriceEqualsStopPrice(): void
    {
        $engine = $this->makeEngine(new FeeCalculator(0, 0), new SlippageCalculator(0));
        $wallet = new Wallet('USDT');
        $wallet->setBalance('USDT', 100_000);
        $wallet->setBalance('BTC', 1);   // 持有 1 BTC（long）
        // 构造一个 long：open 50000，amount 1
        $trade = $this->makeFakeLongTrade(0, 50_000.0, 1.0, 50_000.0);

        // row close 还在 49,000（没跌多少）但 low 45,000 已经跌破 stop (3% → 48500)
        $row = $this->makeRow(1_000, 49000, 49500, 45000, 49000, 5);
        $engine->executeExit($trade, $row, ExitType::STOP_LOSS, 48_500, '', $wallet);

        $this->assertTrue($trade->isClosed());
        $this->assertEquals(ExitType::STOP_LOSS, $trade->getExitReason());
        // 成交均价 = 48500（止损价，既不是 low=45000 也不是 close=49000）
        $this->assertEqualsWithDelta(48_500, $trade->getCloseRate(), 0.01);
    }

    // ---- 5. 手续费 maker vs taker ----

    public function testFeeMakerTakerCorrectlyApplied(): void
    {
        $fee = new FeeCalculator(0.0002, 0.0004); // maker 0.02% taker 0.04%
        // 吃单 market：0.04%
        $this->assertEquals(0.04, $fee->calculate(OrderType::MARKET, 1, 100));
        // 挂单 limit：0.02%
        $this->assertEquals(0.02, $fee->calculate(OrderType::LIMIT, 1, 100));
        // stop loss 也属于吃单：0.04%
        $this->assertEquals(0.04, $fee->calculate(OrderType::STOP_LOSS, 1, 100));
    }

    // ---- 6. Wallet debit/credit 必须精确 + canAfford 防超支 ----

    public function testWalletCannotOverdraw(): void
    {
        $w = new Wallet('USDT');
        $w->setBalance('USDT', 100.0);
        $this->expectException(\InvalidArgumentException::class);
        $w->debit('USDT', 150.0);
    }

    public function testSnapshotTotalsBaseToStake(): void
    {
        $w = new Wallet('USDT');
        $w->setBalance('USDT', 10_000);
        $w->setBalance('BTC', 0.5);
        $snap = $w->snapshot(1_000_000, ['BTC' => 50_000.0]);
        $this->assertEqualsWithDelta(10_000 + 0.5 * 50_000, $snap->getTotalStake(), 0.01);
    }

    // ---- 7. ProtectionManager 冷却锁 ----

    public function testProtectionRejectsLockedPair(): void
    {
        $p = new ProtectionManager(['stop_loss' => 3_600_000], 0);
        $symbol = TradingSymbol::parse('BTC/USDT');
        $p->addLock('BTC/USDT', 3_600_000, 0, 'stop_loss');
        $strategy = new EmaCrossStrategy();
        $violation = $p->checkEntryAllowed([], $symbol, $strategy, 60_000, false);
        $this->assertNotNull($violation, '1 分钟时仍被冷却锁拦截');
        // 3_600_000 以后通过
        $this->assertNull($p->checkEntryAllowed([], $symbol, $strategy, 3_600_001, false));
    }

    // ---- 8. PerformanceReport 核心指标 ----

    public function testPerformanceMetricsCalculation(): void
    {
        // 构造 mock BacktestResult：3 笔平仓、6 天权益曲线（每天 10% 线性增长）
        $initial = 10_000;
        $trades = [
            $this->fakeClosedTradeArray(1,  500, 0.05, 60, ExitType::EXIT_SIGNAL),
            $this->fakeClosedTradeArray(2,  500, -0.02, 30, ExitType::STOP_LOSS),
            $this->fakeClosedTradeArray(3,  500, 0.10, 120, ExitType::ROI),
        ];
        $curve = [];
        for ($i = 0; $i < 6; $i++) {
            $ts = (1_700_000_000 + $i * 86_400) * 1000;
            // 每天涨 10%
            $curve[] = [
                'timestamp' => $ts,
                'total'     => $initial * pow(1.1, $i),
                'stake_currency' => 'USDT',
                'iso' => '',
                'currencies' => [],
            ];
        }
        $result = new \App\Services\Trader\BacktestResult(
            'Mock', '1.0', 'backtest', 'spot', 'USDT', '1h',
            $trades, $curve, 3, 0, end($curve)['total']
        );
        $perf = new PerformanceReport($result, $initial, 365);
        $m = $perf->all();
        // 交易数 3，1 亏损 → 胜率 2/3
        $this->assertSame(3, $m['total_trades']);
        $this->assertEqualsWithDelta(2 / 3 * 100, $m['win_rate_pct'], 0.1);
        // 最终 10000 × 1.1^5 = 16105.1
        $this->assertEqualsWithDelta(16105.1, $m['final_capital'], 0.05);
        // MDD = 0%（连续增长无回撤）
        $this->assertEqualsWithDelta(0, $m['max_drawdown_pct'], 1e-6);
    }

    // ---- 9. E2E：ServiceProvider + Backtesting 跑 21 根 K 线（震荡上升），EmaCross 应该产生 1-2 笔 ----

    public function testE2eEmaCrossProducesTradesAndMetrics(): void
    {
        if (!extension_loaded('trader')) {
            $this->markTestSkipped('缺少 trader 扩展，跳过 Backtesting E2E 测试。');
        }
        $dp = new ArrayDataProvider();
        $symbol = TradingSymbol::parse('BTC/USDT');

        // 生成 180 根 5m K 线：
        //   Phase 1 (i<60):   横盘（价格稳定 ≈ 100）
        //   Phase 2 (60≤i<120): 每根涨 0.8，EMA short 必然交叉上穿 EMA long → 入场信号
        //   Phase 3 (i≥120): 每根跌 0.9，EMA 必然下穿 → 出场信号
        $candles = [];
        $startTs = (int) floor(1_700_000_000_000 / 300_000) * 300_000;
        $p = 100.0;
        for ($i = 0; $i < 180; $i++) {
            $ts = $startTs + $i * 300_000; // 5m
            if ($i < 60) {
                $delta = 0.0;   // 横盘
            } elseif ($i < 120) {
                $delta = 0.8;  // 稳步上行
            } else {
                $delta = -0.9; // 下行
            }
            $o = $p;
            $c = $o + $delta;
            $h = max($o, $c) + 0.3;
            $l = min($o, $c) - 0.3;
            $candles[] = new Candle($ts, $o, $h, $l, $c, 100 + $i);
            $p = $c;
        }
        $dp->setCandles($symbol, '5m', $candles);

        $strategy = new EmaCrossStrategy(5, 15, 0.0); // 无过滤器，保证信号只要交叉就出
        $cfg = [
            'stake_currency'  => 'USDT',
            'initial_capital' => 10_000.0,
            'warmup_candles'  => 20,  // EMA15 种子期
            'run_mode'        => 'backtest',
            'trading_mode'    => 'spot',
            'fee' => ['maker_rate' => 0, 'taker_rate' => 0],
            'slippage' => ['default_pct' => 0, 'pair_overrides' => []],
            'protection' => ['default_cooling_ms' => 0, 'by_exit_reason' => []],
        ];

        $backtest = BacktestServiceProvider::build($cfg, $dp, $strategy, null);
        $result = $backtest->run([$symbol], '5m');

        $this->assertGreaterThanOrEqual(1, $result->getTradeCount(), 'EMA(5/15) 在 3 段走势中应该至少 1 笔交易');
        $perf = new PerformanceReport($result, 10_000, 365);
        $this->assertArrayHasKey('total_return_pct', $perf->all());
        $this->assertArrayHasKey('sharpe_ratio',     $perf->all());
    }

    // ---- 10. run() 零参数：symbols + timeframe 全部从 DataProvider 自动推导 ----

    public function testRunWithNoArgsAutoResolvesSymbolsAndTimeframe(): void
    {
        if (!extension_loaded('trader')) {
            $this->markTestSkipped('缺少 trader 扩展，跳过 Backtesting 自动推导测试。');
        }
        $dp = new ArrayDataProvider();
        $symbol = TradingSymbol::parse('BTC/USDT');
        $dp->setCandles($symbol, '5m', $this->makeTrendCandles(180, 300_000));

        $strategy = new EmaCrossStrategy(5, 15, 0.0);
        $backtest = BacktestServiceProvider::build($this->minimalBacktestConfig(), $dp, $strategy, null);

        // 不传任何参数 —— 应自动推导 symbol=BTC/USDT、timeframe=5m
        $result = $backtest->run();

        $this->assertSame('5m', $result->getTimeframe(), 'timeframe 应自动推导为 5m');
        $this->assertGreaterThanOrEqual(1, $result->getTradeCount(), '自动推导后应正常跑出交易');
    }

    public function testRunWithNoArgsAutoResolvesMultipleSymbols(): void
    {
        if (!extension_loaded('trader')) {
            $this->markTestSkipped('缺少 trader 扩展，跳过。');
        }
        $dp = new ArrayDataProvider();
        // 两个交易对、同一周期 → run() 零参应把两个都回测
        $dp->setCandles(TradingSymbol::parse('BTC/USDT'), '5m', $this->makeTrendCandles(180, 300_000));
        $dp->setCandles(TradingSymbol::parse('ETH/USDT'), '5m', $this->makeTrendCandles(180, 300_000));

        $this->assertCount(2, $dp->getAvailableSymbols());
        $this->assertSame(['5m'], $dp->getAvailableTimeframes());

        $strategy = new EmaCrossStrategy(5, 15, 0.0);
        $backtest = BacktestServiceProvider::build($this->minimalBacktestConfig(), $dp, $strategy, null);

        $result = $backtest->run(); // 零参数
        $this->assertSame('5m', $result->getTimeframe());
        $this->assertGreaterThanOrEqual(1, $result->getTradeCount());
    }

    // ---- 11. run() 零参数但 provider 有多个周期 → 歧义，必须报错让用户显式指定 ----

    public function testRunWithAmbiguousTimeframeThrows(): void
    {
        if (!extension_loaded('trader')) {
            $this->markTestSkipped('缺少 trader 扩展，跳过。');
        }
        $dp = new ArrayDataProvider();
        $symbol = TradingSymbol::parse('BTC/USDT');
        $dp->setCandles($symbol, '5m', $this->makeTrendCandles(180, 300_000));
        // 同一 symbol 再塞一份 1h → provider 内出现两个周期
        $dp->setCandles($symbol, '1h', $this->makeTrendCandles(180, 3_600_000));

        $this->assertSame(['5m', '1h'], $dp->getAvailableTimeframes());

        $strategy = new EmaCrossStrategy(5, 15, 0.0);
        $backtest = BacktestServiceProvider::build($this->minimalBacktestConfig(), $dp, $strategy, null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('多个周期');
        $backtest->run();
    }

    // ---- 12. run() 零参数但 provider 为空 → 报"无交易对/数据"错误 ----

    public function testRunWithEmptyProviderThrows(): void
    {
        $dp = new ArrayDataProvider(); // 不塞任何数据
        $this->assertSame([], $dp->getAvailableSymbols());
        $this->assertSame([], $dp->getAvailableTimeframes());

        $strategy = new EmaCrossStrategy(5, 15, 0.0);
        $backtest = BacktestServiceProvider::build($this->minimalBacktestConfig(), $dp, $strategy, null);

        // 空 provider：自动推导拿到 0 个 symbol，直接报错（symbols 检查先于 timeframe 推导）
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs at least one symbol');
        $backtest->run();
    }

    // ---- 13. ArrayDataProvider::getAvailableTimeframes 去重 ----

    public function testArrayDataProviderGetAvailableTimeframesDedup(): void
    {
        $dp = new ArrayDataProvider();
        $btc = TradingSymbol::parse('BTC/USDT');
        $eth = TradingSymbol::parse('ETH/USDT');

        // 空 provider
        $this->assertSame([], $dp->getAvailableTimeframes());

        // 两个 symbol 都是 5m → 去重后仅 1 个
        $dp->setCandles($btc, '5m', $this->makeTrendCandles(50, 300_000));
        $dp->setCandles($eth, '5m', $this->makeTrendCandles(50, 300_000));
        $this->assertSame(['5m'], $dp->getAvailableTimeframes());

        // 再加一个 1h → 2 个，保持首次出现顺序
        $dp->setCandles($btc, '1h', $this->makeTrendCandles(50, 3_600_000));
        $this->assertSame(['5m', '1h'], $dp->getAvailableTimeframes());
    }

    // ====== helpers ======

    /**
     * 生成 n 根按 $stepMs 严格对齐、带三段趋势的合法 K 线（横盘 → 上涨 → 下跌）
     *
     * 供 EmaCross E2E / 自动推导测试使用：三段走势保证 EMA 短周期必然上穿/下穿长周期。
     *
     * @return Candle[]
     */
    private function makeTrendCandles(int $n, int $stepMs): array
    {
        $startTs = (int) floor(1_700_000_000_000 / $stepMs) * $stepMs;
        $third   = (int) floor($n / 3);
        $candles = [];
        $p = 100.0;
        for ($i = 0; $i < $n; $i++) {
            $ts = $startTs + $i * $stepMs;
            if ($i < $third) {
                $delta = 0.0;   // 横盘
            } elseif ($i < $third * 2) {
                $delta = 0.8;   // 稳步上行
            } else {
                $delta = -0.9;  // 下行
            }
            $o = $p;
            $c = $o + $delta;
            $h = max($o, $c) + 0.3;
            $l = min($o, $c) - 0.3;
            $candles[] = new Candle($ts, $o, $h, $l, $c, 100 + $i);
            $p = $c;
        }
        return $candles;
    }

    /**
     * 最小可跑 Backtesting 的配置（零手续费 / 零滑点 / 零冷却，消除干扰）
     */
    private function minimalBacktestConfig(): array
    {
        return [
            'stake_currency'  => 'USDT',
            'initial_capital' => 10_000.0,
            'warmup_candles'  => 20,
            'run_mode'        => 'backtest',
            'trading_mode'    => 'spot',
            'fee' => ['maker_rate' => 0, 'taker_rate' => 0],
            'slippage' => ['default_pct' => 0, 'pair_overrides' => []],
            'protection' => ['default_cooling_ms' => 0, 'by_exit_reason' => []],
        ];
    }

    private function makeEngine(FeeCalculator $fee, SlippageCalculator $sl): MatchingEngine
    {
        return new MatchingEngine($fee, $sl, new ExitRules(), 'USDT');
    }

    /**
     * 生成 12 列矩阵行（SignalCols 格式）
     *
     * @return array<int, mixed>
     */
    private function makeRow(int $ts, float $o, float $h, float $l, float $c, float $v): array
    {
        $row = array_fill(0, SignalCols::NUM_COLUMNS, 0);
        $row[SignalCols::DATE]   = $ts;
        $row[SignalCols::OPEN]   = $o;
        $row[SignalCols::HIGH]   = $h;
        $row[SignalCols::LOW]    = $l;
        $row[SignalCols::CLOSE]  = $c;
        $row[SignalCols::VOLUME] = $v;
        $row[SignalCols::ENTER_TAG] = '';
        $row[SignalCols::EXIT_TAG]  = '';
        return $row;
    }

    private function makeFakeLongTrade(int $openTs, float $openRate, float $amount = 0.01, float $stake = 1000.0): TradeRecord
    {
        $symbol = TradingSymbol::parse('BTC/USDT');
        $t = new TradeRecord([
            'id' => 1,
            'symbol' => $symbol,
            'is_short' => false,
            'trading_mode' => TradingMode::SPOT,
            'leverage' => 1,
        ]);
        // order_timestamp 必须 > 0；如果传 0 兜底给 1
        $safeTs = max(1, $openTs);
        $fillTs = $safeTs;
        // 直接用 OrderRecord 模拟 1 次入场成交
        $order = new \App\Services\Trader\Model\OrderRecord([
            'id' => 1,
            'symbol' => (string) $symbol,
            'side' => OrderSide::BUY,
            'type' => OrderType::MARKET,
            'price' => $openRate,
            'amount' => $amount,
            'order_timestamp' => $safeTs,
            'entry_side' => true,
            'stake_amount' => $stake,
        ]);
        $order->applyFill($amount, $openRate, 0.0, $fillTs);
        $t->setStakeAmount($stake);
        $t->attachEntryOrder($order);
        return $t;
    }

    /**
     * TradeRecord::toArray() 的扁平假数据（给 PerformanceReport 用）
     */
    private function fakeClosedTradeArray(int $id, float $stake, float $pct, int $durMin, string $reason): array
    {
        return [
            'trade_id' => $id,
            'pair' => 'BTC/USDT',
            'direction' => 'long',
            'trading_mode' => 'spot',
            'leverage' => 1,
            'stake_amount' => $stake,
            'amount' => $stake / 50_000,
            'open_rate' => 50_000,
            'close_rate' => 50_000 * (1 + $pct),
            'open_timestamp' => 1_000_000 + $id * 3_600_000,
            'close_timestamp' => 1_000_000 + ($id + $durMin) * 60_000,
            'open_date_utc' => null,
            'close_date_utc' => null,
            'is_open' => false,
            'close_reason' => $reason,
            'enter_tag' => '',
            'exit_tag' => '',
            'fee_open' => 0,
            'fee_close' => 0,
            'funding_interest' => 0,
            'liquidation_price' => 0,
            'min_rate' => 0,
            'max_rate' => 0,
            'nr_entries' => 1,
            'nr_exits' => 1,
            'duration_minutes' => $durMin,
            'close_profit_abs' => $stake * $pct,
            'close_profit_ratio' => $pct,
        ];
    }
}
