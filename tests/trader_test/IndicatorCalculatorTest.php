<?php

namespace Sikelan\Tests\trader_test;

use App\Services\Trader\Strategy\IndicatorCalculator;
use PHPUnit\Framework\TestCase;

/**
 * IndicatorCalculator 新增指标的数学 & 契约测试
 *
 * 覆盖目标：
 *   A. 均线类：WMA / DEMA / TEMA / TRIMA / KAMA / MA
 *   B. 动量震荡类：CMO / ROC / MOM / WILLR / CCI / STOCH(K,D) / STOCHF / STOCHRSI / ULTOSC / APO / PPO / MACD（DIF, DEA, HIST）
 *   C. 波动率：VARIANCE / TRANGE / ATR / NATR
 *   D. 趋势强度：ADX / ADXR / +DI / −DI / AROON(up,down) / AROONOSC / SAR
 *   E. 量价类：MFI / OBV / AD / ADOSC
 *   F. 价格合成：AVGPRICE / TYPPRICE / WCLPRICE / MEDPRICE
 *
 * 每个用例做 3 层契约校验：
 *   1) 输出长度 == 输入长度（不能出现稀疏索引；方便策略直接 [i] 下标访问）
 *   2) 值域是否在约定区间（如 WILLR ∈ [-100, 0]，RSI ∈ [0, 100]）
 *   3) 已知简单输入能否得到解析解（如 MACD 全横盘 → DIF/DEA/HIST 均≈0）
 *
 * 注意：所有方法都依赖 trader 扩展，若未安装 markTestSkipped。
 */
class IndicatorCalculatorTest extends TestCase
{
    /** @var array{open:float[],high:float[],low:float[],close:float[],volume:float[]} 80 根合成 K 线 */
    private array $m;
    /** @var int */
    private int $n;

    protected function setUp(): void
    {
        if (!extension_loaded('trader')) {
            $this->markTestSkipped('缺少 PHP trader 扩展，跳过 IndicatorCalculator 测试。');
        }
        // 合成 80 根：前 20 横盘 → 20-50 稳定上升 → 50-80 回落
        $this->n = 80;
        $p = 100.0;
        for ($i = 0; $i < $this->n; $i++) {
            if ($i < 20)       $delta = 0.0;
            elseif ($i < 50)   $delta = +0.7;
            else               $delta = -0.9;
            $o = $p;
            $c = $o + $delta;
            $h = max($o, $c) + 0.5;
            $l = min($o, $c) - 0.5;
            $this->m['open'][]   = $o;
            $this->m['high'][]   = $h;
            $this->m['low'][]    = $l;
            $this->m['close'][]  = $c;
            $this->m['volume'][] = 100 + $i * 3;
            $p = $c;
        }
    }

    // -------- 辅助断言 --------

    /**
     * 断言：每个一维数组的 count == N，且键为 [0..N-1]，所有值 ∈ [min,max]（可选）
     *
     * @param float[] $arr
     */
    private function assertLen(array $arr, int $expectedN, string $label = '', ?float $min = null, ?float $max = null): void
    {
        $this->assertCount($expectedN, $arr, "{$label} count mismatch");
        $this->assertSame(range(0, $expectedN - 1), array_keys($arr), "{$label} 索引非连续 0..N-1");
        if ($min !== null) {
            $m = min($arr);
            $this->assertGreaterThanOrEqual($min - 1e-9, $m, "{$label} min($m) < min($min)");
        }
        if ($max !== null) {
            $M = max($arr);
            $this->assertLessThanOrEqual($max + 1e-9, $M, "{$label} max($M) > max($max)");
        }
    }

    // ====================================================================
    //  A. 均线类
    // ====================================================================

    public function testMaFamiliesOutputsAlignToN(): void
    {
        // WMA：最后一个值权重最大 → 上涨段 WMA 比 SMA 稍高（合理）
        $wma  = IndicatorCalculator::wma($this->m['close'], 10);
        $sma  = IndicatorCalculator::sma($this->m['close'], 10);
        $this->assertLen($wma, $this->n, 'WMA(10)');
        $this->assertLen($sma, $this->n, 'SMA(10)');

        // 上涨段（i=40, 正中间）WMA 因权重前倾，应该 >= SMA（或至少接近，避免方向错）
        $this->assertGreaterThanOrEqual($sma[40] * 0.99, $wma[40], '上涨段 WMA 不应显著低于 SMA');

        // DEMA / TEMA / TRIMA / KAMA / MA(SMA/EMA) 长度对齐
        $this->assertLen(IndicatorCalculator::dema($this->m['close'], 10),  $this->n, 'DEMA(10)');
        $this->assertLen(IndicatorCalculator::tema($this->m['close'], 10),  $this->n, 'TEMA(10)');
        $this->assertLen(IndicatorCalculator::trima($this->m['close'], 10), $this->n, 'TRIMA(10)');
        $this->assertLen(IndicatorCalculator::kama($this->m['close'], 10),  $this->n, 'KAMA(10)');

        // ma() 与各专用函数结果相等
        $this->assertSame(
            IndicatorCalculator::sma($this->m['close'], 10),
            IndicatorCalculator::ma($this->m['close'], 10, \TRADER_MA_TYPE_SMA),
            'ma(SMA) 结果应等价 sma()'
        );
    }

    // ====================================================================
    //  B. 动量震荡类
    // ====================================================================

    public function testMomentumOscillatorsRangeAndAlign(): void
    {
        $h = $this->m['high']; $l = $this->m['low']; $c = $this->m['close']; $N = $this->n;

        // CMO [-100, +100]
        $this->assertLen(IndicatorCalculator::cmo($c, 14), $N, 'CMO(14)', -100, 100);

        // ROC(10) 理论解析解：第 10 根开始 (close[i]/close[i−10] − 1)×100
        $roc = IndicatorCalculator::roc($c, 10);
        $this->assertLen($roc, $N, 'ROC(10)');
        // i=11 是上涨段第一根"完整" 10 期 ROC，delta=0.7，则 ROC(11) ≈ (close[10] 和 close[1] 相同→横盘前9根后)≈ 0；
        // i=30 已连续上涨 20 根：close[30] = 100 + 10*0.7 = 107，close[20] = 100+0=100 → 7%
        $this->assertEqualsWithDelta(7.0, $roc[30], 0.5, '上涨第 30 根 ROC(10) ≈ 7%');

        // MOM = close[i] - close[i-10]
        $mom = IndicatorCalculator::mom($c, 10);
        $this->assertLen($mom, $N, 'MOM(10)');
        $this->assertEqualsWithDelta(7.0, $mom[30], 0.5, '上涨第 30 根 MOM(10) ≈ +7');

        // WILLR ∈ [-100, 0]
        $this->assertLen(IndicatorCalculator::willr($h, $l, $c, 14), $N, 'WILLR(14)', -100, 0);
        // CCI
        $this->assertLen(IndicatorCalculator::cci($h, $l, $c, 14), $N, 'CCI(14)');

        // STOCH：两列、值域 [0,100]
        [$slowK, $slowD] = IndicatorCalculator::stoch($h, $l, $c);
        $this->assertLen($slowK, $N, 'STOCH slowK', 0, 100);
        $this->assertLen($slowD, $N, 'STOCH slowD', 0, 100);
        // 经典：D 是 K 的 MA → D 比 K 滞后（简单看数组长度对齐即可）

        // STOCHF & STOCHRSI
        [$fk, $fd] = IndicatorCalculator::stochf($h, $l, $c);
        $this->assertLen($fk, $N, 'STOCHF fastK', 0, 100);
        $this->assertLen($fd, $N, 'STOCHF fastD', 0, 100);

        [$sK, $sD] = IndicatorCalculator::stochRsi($c);
        $this->assertLen($sK, $N, 'STOCHRSI K', 0, 100);
        $this->assertLen($sD, $N, 'STOCHRSI D', 0, 100);

        // ULTOSC [0, 100]
        $this->assertLen(IndicatorCalculator::ultOsc($h, $l, $c), $N, 'ULTOSC', 0, 100);

        // APO & PPO（长度 & PPO 百分比近似 MACD / close_ratio）
        $apo = IndicatorCalculator::apo($c);
        $ppo = IndicatorCalculator::ppo($c);
        $this->assertLen($apo, $N, 'APO');
        $this->assertLen($ppo, $N, 'PPO');

        // MACD: 三条线对齐，横盘前 19 根 hist≈0
        [$macd, $sig, $hist] = IndicatorCalculator::macd($c);
        $this->assertLen($macd, $N, 'MACD');
        $this->assertLen($sig,  $N, 'SIGNAL');
        $this->assertLen($hist, $N, 'HIST');
        // 启动期都是 close 不变 → DIF≈0、SIGNAL≈0、柱≈0
        $this->assertEqualsWithDelta(0.0, $hist[10], 0.01, '横盘启动期 MACD histogram ≈ 0');
    }

    // ====================================================================
    //  C. 波动率 / 波幅类
    // ====================================================================

    public function testVolatilityIndicatorsAlignAndNonNegative(): void
    {
        $h = $this->m['high']; $l = $this->m['low']; $c = $this->m['close']; $N = $this->n;

        // trange（真波幅）：≥ 0；i=1 已知解析解
        $tr = IndicatorCalculator::trange($h, $l, $c);
        $this->assertLen($tr, $N, 'TRANGE', 0, null);
        // i=0 没有前 C，用 h-l（这里 h=100.5, l=99.5 → 1.0）
        $this->assertEqualsWithDelta(1.0, $tr[0], 1e-6, 'TR[0] = h - l');

        // variance = stddev²，非负
        $va = IndicatorCalculator::variance($c, 10);
        $this->assertLen($va, $N, 'VARIANCE', 0, null);
        // 横盘 i<20 variance 接近 0（仅 0.0 或微小舍入噪声）
        $this->assertLessThanOrEqual(0.5, $va[10], '横盘 variance 应接近 0');

        // ATR & NATR ≥ 0
        $atr  = IndicatorCalculator::atr($h, $l, $c);
        $natr = IndicatorCalculator::natr($h, $l, $c);
        $this->assertLen($atr,  $N, 'ATR',  0, null);
        $this->assertLen($natr, $N, 'NATR', 0, null);
        // NATR = ATR/close ×100 → 近似关系
        $this->assertEqualsWithDelta($atr[60] / max(1e-9, $c[60]) * 100, $natr[60], 0.05, 'NATR ≈ ATR/C×100');
    }

    // ====================================================================
    //  D. 趋势方向/强度类
    // ====================================================================

    public function testTrendIndicatorsAlignAndRange(): void
    {
        $h = $this->m['high']; $l = $this->m['low']; $c = $this->m['close']; $N = $this->n;

        // ADX / ADXR ∈ [0,100]
        $this->assertLen(IndicatorCalculator::adx($h, $l, $c),  $N, 'ADX',  0, 100);
        $this->assertLen(IndicatorCalculator::adxr($h, $l, $c), $N, 'ADXR', 0, 100);

        // ±DI ∈ [0,100]；且上涨段 plusDI > minusDI
        $pDi = IndicatorCalculator::plusDi($h, $l, $c);
        $mDi = IndicatorCalculator::minusDi($h, $l, $c);
        $this->assertLen($pDi, $N, '+DI', 0, 100);
        $this->assertLen($mDi, $N, '-DI', 0, 100);
        $this->assertGreaterThan($mDi[40], $pDi[40], '连续上涨段 +DI > −DI');
        $this->assertGreaterThan($pDi[70], $mDi[70], '快速下跌段 −DI > +DI');

        // AROON 两列 [0,100]；aroonOsc ∈ [-100,100]
        [$up, $dn] = IndicatorCalculator::aroon($h, $l);
        $osc       = IndicatorCalculator::aroonOsc($h, $l);
        $this->assertLen($up,  $N, 'AROON_UP', 0, 100);
        $this->assertLen($dn,  $N, 'AROON_DOWN', 0, 100);
        $this->assertLen($osc, $N, 'AROONOSC', -100, 100);
        // 解析关系：Osc = Up − Down
        for ($i = 0; $i < $N; $i++) {
            $this->assertEqualsWithDelta($up[$i] - $dn[$i], $osc[$i], 0.01, "aroonOsc[$i] = Up - Dn");
        }

        // SAR 长度 & 上涨段 SAR 位于价格下方（趋势追踪）
        $sar = IndicatorCalculator::sar($h, $l);
        $this->assertLen($sar, $N, 'SAR');
        // 上涨到 i=30：SAR 应该低于当前 low（多头保护价）
        $this->assertLessThanOrEqual($l[30] + 1e-6, $sar[30], '上涨段 SAR ≤ LOW（long trailing stop）');
    }

    // ====================================================================
    //  E. 量价类
    // ====================================================================

    public function testVolumePriceIndicatorsAlignAndCorrectness(): void
    {
        $h = $this->m['high']; $l = $this->m['low']; $c = $this->m['close']; $v = $this->m['volume'];
        $N = $this->n;

        // MFI ∈ [0, 100]
        $this->assertLen(IndicatorCalculator::mfi($h, $l, $c, $v), $N, 'MFI', 0, 100);

        // OBV：横盘（前 20 根 close 都没动）OBV 不增加
        $obv = IndicatorCalculator::obv($c, $v);
        $this->assertLen($obv, $N, 'OBV');
        $this->assertEqualsWithDelta($v[0], $obv[0], 0.001, 'OBV[0] = volume[0]');
        // 前 20 根 close 横盘：收盘全部 == 100 → OBV 应该保持 = volume[0]
        for ($i = 1; $i < 20; $i++) {
            $this->assertEqualsWithDelta($obv[0], $obv[$i], 0.001, "横盘 OBV[$i] 应不变");
        }

        // AD & ADOSC
        $this->assertLen(IndicatorCalculator::ad($h, $l, $c, $v),     $N, 'AD');
        $this->assertLen(IndicatorCalculator::adOsc($h, $l, $c, $v), $N, 'ADOSC');
    }

    // ====================================================================
    //  F. 价格合成类
    // ====================================================================

    public function testPriceAggregatorsAnalyticalValues(): void
    {
        $o = $this->m['open']; $h = $this->m['high']; $l = $this->m['low']; $c = $this->m['close'];
        $N = $this->n;

        $avg = IndicatorCalculator::avgPrice($o, $h, $l, $c);
        $typ = IndicatorCalculator::typPrice($h, $l, $c);
        $wcl = IndicatorCalculator::wclPrice($h, $l, $c);
        $med = IndicatorCalculator::medPrice($h, $l);

        $this->assertLen($avg, $N, 'AVGPRICE');
        $this->assertLen($typ, $N, 'TYPPRICE');
        $this->assertLen($wcl, $N, 'WCLPRICE');
        $this->assertLen($med, $N, 'MEDPRICE');

        // 对 i=0（已知解析解）校验
        // i=0: o=100, h=100.5, l=99.5, c=100（横盘）
        $this->assertEqualsWithDelta(100.0, $avg[0], 1e-6, 'AVG(100, 100.5, 99.5, 100) = 100');
        $this->assertEqualsWithDelta(100.0, $typ[0], 1e-6, 'TYP(100.5, 99.5, 100) = 100');
        $this->assertEqualsWithDelta(100.0, $wcl[0], 1e-6, 'WCL(100.5,99.5,2·100)/4 = 100');
        $this->assertEqualsWithDelta(100.0, $med[0], 1e-6, 'MED(100.5,99.5)/2 = 100');
    }

    // ====================================================================
    //  G. 启动期 & 空输入鲁棒性（极端输入不应抛异常，长度对齐）
    // ====================================================================

    public function testEmptyAndShortInputsReturnCorrectLengthNoException(): void
    {
        // 空
        $this->assertSame([], IndicatorCalculator::wma([], 10));
        $this->assertSame([], IndicatorCalculator::atr([], [], [], 14));
        $this->assertSame([], IndicatorCalculator::adx([], [], [], 14));

        // 短于 period：不抛错，返回默认填充数组，长度 = N
        $shortClose = [1.0, 2.0, 3.0];
        $shortHigh  = [1.1, 2.1, 3.1];
        $shortLow   = [0.9, 1.9, 2.9];
        $shortVol   = [10.0, 20.0, 30.0];

        // RSI period=14 > N=3 → 返回 3 个 50
        $rsi = IndicatorCalculator::rsi($shortClose, 14);
        $this->assertCount(3, $rsi);
        $this->assertSame([50.0, 50.0, 50.0], $rsi);

        // MACD 需要更多启动长度，短输入不抛错
        [$macd, $sig, $hist] = IndicatorCalculator::macd($shortClose);
        $this->assertCount(3, $macd);
        $this->assertCount(3, $sig);
        $this->assertCount(3, $hist);

        // NATR 需要 period-1 + 更多；短输入不抛错，返回 N 个 0
        $natr = IndicatorCalculator::natr($shortHigh, $shortLow, $shortClose, 14);
        $this->assertCount(3, $natr);
        $this->assertSame([0.0, 0.0, 0.0], $natr);

        // ADXR 需要 3*period-2
        $adxr = IndicatorCalculator::adxr($shortHigh, $shortLow, $shortClose, 14);
        $this->assertCount(3, $adxr);

        // price agg 在短输入仍能返回解析结果（不需要 period）
        $this->assertCount(3, IndicatorCalculator::obv($shortClose, $shortVol));
        $this->assertCount(3, IndicatorCalculator::ad($shortHigh, $shortLow, $shortClose, $shortVol));
    }
}
