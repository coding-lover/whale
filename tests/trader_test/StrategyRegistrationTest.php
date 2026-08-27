<?php

namespace Sikelan\Tests\trader_test;

use App\Services\Exchanges\TradingSymbol;
use App\Services\Trader\BacktestServiceProvider;
use App\Services\Trader\Market\Candle;
use App\Services\Trader\PerformanceReport;
use App\Services\Trader\Strategy\StrategyInterface;
use App\Services\Trader\Strategies\BollingerRsiMeanReversionStrategy;
use App\Services\Trader\Strategies\EmaCrossStrategy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * 策略注册表 & 标准策略功能测试
 *
 * 覆盖：
 *   1. BacktestServiceProvider::getStrategyRegistry() 解析（字符串 / 数组两种形式 + 无效 class 报错）
 *   2. createStrategyByName() 按别名创建（数组配置），按完整类名直接创建（退化路径），
 *      不存在的别名抛出错误并打印当前已注册列表
 *   3. BollingerRsiMeanReversionStrategy 构造参数注入（非默认参数传入，getBbPeriod/RSI 验证）
 *   4. BollingerRsiMeanReversionStrategy 技术指标：SMA / rollingStd / RSI 数学正确性
 *   5. 端到端：通过 BacktestServiceProvider::createStrategyByName() 创建 + 在 400 根 5m
 *      合成布林带形态数据跑回测 → 至少 1 笔交易（说明注册逻辑 + 策略与编排器打通）
 */
class StrategyRegistrationTest extends TestCase
{
    // ----- 1. registry 规范化 + 校验 -----

    public function testRegistryNormalizesStringAndArray(): void
    {
        $cfg = [
            'strategies' => [
                'Short1' => EmaCrossStrategy::class,                           // 形式 A：字符串
                'Short2' => ['class' => BollingerRsiMeanReversionStrategy::class,
                             'construct' => [15, 1.8, 9, 30, 70, 0.9]],   // 形式 B：数组
            ],
        ];
        $reg = BacktestServiceProvider::getStrategyRegistry($cfg);

        $this->assertArrayHasKey('Short1', $reg);
        $this->assertArrayHasKey('Short2', $reg);
        $this->assertSame(EmaCrossStrategy::class, $reg['Short1']['class']);
        $this->assertSame([], $reg['Short1']['construct']);
        $this->assertSame([15, 1.8, 9, 30, 70, 0.9], $reg['Short2']['construct']);
    }

    public function testRegistryRejectsInvalidStrategyClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BacktestServiceProvider::getStrategyRegistry([
            'strategies' => ['Bad' => \stdClass::class],
        ]);
    }

    // ----- 2. createStrategyByName：别名 vs 完整类名 -----

    public function testCreateStrategyByNameViaRegistry(): void
    {
        $cfg = [
            'strategies' => [
                'MeanRevStd' => [
                    'class'     => BollingerRsiMeanReversionStrategy::class,
                    'construct' => [10, 1.5, 9, 35.0, 60.0, 0.7],
                ],
            ],
        ];
        /** @var BollingerRsiMeanReversionStrategy $s */
        $s = BacktestServiceProvider::createStrategyByName($cfg, 'MeanRevStd');
        $this->assertInstanceOf(StrategyInterface::class, $s);
        $this->assertSame(10,    $s->getBbPeriod());
        $this->assertSame(9,     $s->getRsiPeriod());
        $this->assertEqualsWithDelta(1.5, $s->getBbStdMult(), 0.001);
    }

    public function testCreateStrategyByNameFallbackFullClassName(): void
    {
        // 即使注册表空，直接传完整类名也能创建（退化路径，不用改 config 就能调试）
        $s = BacktestServiceProvider::createStrategyByName([], EmaCrossStrategy::class);
        $this->assertInstanceOf(EmaCrossStrategy::class, $s);
    }

    public function testCreateStrategyByNameMissingThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('当前已注册策略: [MeanRevStd, EmaCross20_50]');
        // 使用和 config/trader.php 相同结构但只有一个别名
        BacktestServiceProvider::createStrategyByName([
            'strategies' => [
                'MeanRevStd'     => BollingerRsiMeanReversionStrategy::class,
                'EmaCross20_50'  => EmaCrossStrategy::class,
            ],
        ], 'DoesNotExist');
    }

    // ----- 3. Bollinger 策略技术指标：数学正确性 -----

    public function testSmaMath(): void
    {
        // SMA([1,2,3,4,5],3) -> 最后一个 = (3+4+5)/3 = 4
        $smaArr = BollingerRsiMeanReversionStrategy::sma([1,2,3,4,5], 3);
        $last = end($smaArr);
        $this->assertEqualsWithDelta(4.0, $last, 1e-6);
    }

    public function testRollingStdMath(): void
    {
        // [1,2,3], period=3 → 方差 = ( (1-2)² + (2-2)² + (3-2)² ) / (3-1) = (1+0+1)/2 = 1 → σ = 1
        $stdArr = BollingerRsiMeanReversionStrategy::rollingStd([1,2,3], 3);
        $last = end($stdArr);
        $this->assertEqualsWithDelta(1.0, $last, 1e-6);
    }

    public function testRsiAlwaysFlat50IfPriceUnchanged(): void
    {
        $arr = array_fill(0, 30, 100.0); // 30 根都 100
        $rsi = BollingerRsiMeanReversionStrategy::rsi($arr, 14);
        // 所有值都应该在 [49.999, 50.001] 区间（理论上完全持平）
        foreach ($rsi as $i => $v) {
            $this->assertEqualsWithDelta(50.0, $v, 0.01, "RSI at index {$i} should be 50 for flat price");
        }
    }

    // ----- 4. E2E：注册表 → createStrategyByName → Backtesting.run → 出至少 1 笔 -----

    public function testE2eRegisteredStrategyRunsAndProducesTrades(): void
    {
        // 构造 400 根 5m 价格，合成"布林带周期性跌破"形态：
        //  Phase A (i<150): 横盘 ≈100 → Phase B(150-300): 先跌破到 92，再回到 100（均值回归完成1笔）
        //                    → Phase C(>300) 再跌破再回拉（2 笔完整）
        $candles = [];
        $startTs = (int) floor(1_700_000_000_000 / 300_000) * 300_000;
        $p = 100.0;
        $n = 400;
        for ($i = 0; $i < $n; $i++) {
            $ts = $startTs + $i * 300_000;
            if ($i < 100) {
                $delta = 0.0;  // 横盘
            } elseif ($i < 150) {
                $delta = -0.15; // 慢慢下探
            } elseif ($i < 200) {
                $delta = +0.2;  // 回到 100 上方
            } elseif ($i < 250) {
                $delta = 0.0;
            } elseif ($i < 300) {
                $delta = -0.2;  // 再下探（2 次超卖）
            } else {
                $delta = +0.25; // 强势反弹
            }
            $o = $p;
            $c = $o + $delta;
            $h = max($o, $c) + 0.25;
            $l = min($o, $c) - 0.25;
            $v = 500.0 + 200 * abs($delta); // 动得大 量能也放大（过 vol_filter）
            $candles[] = new Candle($ts, $o, $h, $l, $c, $v);
            $p = $c;
        }

        $dp = BacktestServiceProvider::createArrayDataProvider();
        $dp->setCandles(TradingSymbol::parse('BTC/USDT'), '5m', $candles, true);

        $cfg = [
            'stake_currency' => 'USDT',
            'initial_capital' => 10_000,
            'warmup_candles' => 30,
            'fee' => ['maker_rate' => 0, 'taker_rate' => 0],
            'slippage' => ['default_pct' => 0, 'pair_overrides' => []],
            'protection' => ['default_cooling_ms' => 0, 'by_exit_reason' => []],
            'strategies' => [
                'MeanRevStd' => [
                    'class'     => BollingerRsiMeanReversionStrategy::class,
                    'construct' => [20, 2.0, 14, 35.0, 65.0, 0.5],  // 放宽条件便于在合成数据中出交易
                ],
            ],
        ];

        $strategy = BacktestServiceProvider::createStrategyByName($cfg, 'MeanRevStd');
        $backtest = BacktestServiceProvider::build($cfg, $dp, $strategy, null);
        $result = $backtest->run([TradingSymbol::parse('BTC/USDT')], '5m');
        $this->assertGreaterThanOrEqual(1, $result->getTradeCount(), '合成震荡行情 + 均值回归策略应该至少 1 笔');
        $perf = new PerformanceReport($result, 10_000, 365);
        $this->assertArrayHasKey('sharpe_ratio', $perf->all());
    }
}
