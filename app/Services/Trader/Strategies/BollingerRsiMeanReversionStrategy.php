<?php

namespace App\Services\Trader\Strategies;

use App\Services\Exchanges\TradingSymbol;
use App\Services\Trader\Model\TradeRecord;
use App\Services\Trader\Strategy\AbstractStrategy;
use App\Services\Trader\Strategy\IndicatorCalculator;
use App\Services\Trader\Strategy\SignalCols;

/**
 * 布林带 + RSI 均值回归策略（标准开发模板）
 *
 * ================================================================
 *  逻辑简述（经典"假突破 + 超卖/超买"均值回归组合）：
 * ================================================================
 *   入场 LONG：
 *     ① close < 布林带下轨（BB_LOWER）—— 跌破下轨"超卖"
 *     ② RSI(14) < 30 —— 同时 RSI 确认超卖（过滤假突破）
 *     ③ 过滤：成交量要 > 过去 20 根均量 0.8×（缩量跌破多数是流动性差，避免进去被埋）
 *     标签: 'bb_lower_rsi30'
 *
 *   出场（按优先级执行，ExitRules 会先命中 ROI / StopLoss / Trailing）：
 *     - 固定止损：5%（策略属性配置 stoploss）
 *     - 追踪止损：达到 2% 未实现盈后启动 3% trailing（回 2.5R 止盈）
 *     - ROI 阶梯：0 分钟盈 6% → 30 分钟 3% → 120 分钟 1.5% → 240 分钟 0%（持平就走）
 *     - 信号出场：close > 布林带中轨 (BB_MID) + RSI > 65 —— 标签 'bb_mid_rsi_overbought'
 *     - 保本退出：持仓 > 60 根 5m 且浮盈 < 0.3% 就撤（避免行情不动被手续费磨死）
 *     - HOLD 超时：180 根（15h）强平（maxHoldBars）
 *
 *  注意：本策略为"示例模板"，实际交易请加入样本外验证。
 * ================================================================
 *
 * 配置对照表（对应 AbstractStrategy protected 属性）：
 *   - defaultStakeAmount   单笔 stake：2000 USDT
 *   - maxOpenTrades        最大同时持仓：4
 *   - maxOpenTradesPerPair 每 pair 最大：1
 *   - stoploss             固定止损：5%
 *   - trailingStop         追踪止损：3%（仅 ROI 激活后才启动）
 *   - trailingStopPositive 追踪启动阈值：2% 未实现盈
 *   - minimalRoi           阶梯止盈
 *   - maxHoldBars          超时持仓：180 根
 */
class BollingerRsiMeanReversionStrategy extends AbstractStrategy
{
    // --------------------------------------------------------------
    //  自定义列下标（SignalCols::NUM_COLUMNS(=12) 之后追加）
    // --------------------------------------------------------------
    public const COL_SMA20        = 12; // 布林带中轨（SMA 20）
    public const COL_BB_UPPER     = 13; // 上轨 = SMA20 + 2σ
    public const COL_BB_LOWER     = 14; // 下轨 = SMA20 - 2σ
    public const COL_RSI14        = 15; // RSI 14
    public const COL_VOL_SMA20    = 16; // 20 根成交量 SMA

    // 自定义列数（便于 EmaCrossStrategy 等其他策略做调试：输出列时统一对齐）
    public const EXTRA_COL_COUNT  = 5;

    // --------------------------------------------------------------
    //  风控与资金管理配置（覆写 AbstractStrategy 默认值）
    // --------------------------------------------------------------
    protected $defaultStakeAmount   = 2000.0;  // 每笔 2000 USDT
    protected $maxOpenTrades        = 4;       // 最多 4 个同时持仓（防过度暴露）
    protected $maxOpenTradesPerPair = 1;       // 每 pair 1 个（避免同一标的重仓）

    // 固定止损：5%
    protected $stoploss = 0.05;

    // 最小 ROI 阶梯（开仓分钟数 => 最低目标收益率小数）：
    //   开仓即止盈 6%（防止假突破真反转先锁住利润）
    //   30 分钟后 3%
    //   120 分钟后 1.5%
    //   240 分钟后持平（0%）即走 → 避免行情不动手续费流失
    protected $minimalRoi = [
        0   => 0.06,
        30  => 0.03,
        120 => 0.015,
        240 => 0.00,
    ];

    // 追踪止损：2% 浮盈后启动 3% trailing
    protected $trailingStop         = 0.03;   // 3%
    protected $trailingStopPositive = 0.02;   // 达到 2% 未实现盈才启动 trailing 保护

    // HOLD 超时：180 根（5m × 180 = 15 小时）后无论盈亏强制平
    protected $maxHoldBars = 180;

    // 做空开关（现货策略关闭；Futures 可开）
    protected $enableLong  = true;
    protected $enableShort = false;

    // 基础元信息（打印报表 & 日志区分版本）
    protected $version     = '1.0-std';
    protected $description = '布林带(20,2σ) 跌破下轨 + RSI14<30 超卖 + 量能过滤 · 均值回归 LONG 策略模板';

    // --------------------------------------------------------------
    //  策略参数（给构造函数注入，便于 grid search / 调参）
    // --------------------------------------------------------------
    /** @var int 布林带周期 */
    private $bbPeriod;
    /** @var float 布林带带宽倍数（std 的倍数，常用 2）*/
    private $bbStdMult;
    /** @var int RSI 周期 */
    private $rsiPeriod;
    /** @var float RSI 超卖阈值（入场 LONG：RSI<rsiOversold） */
    private $rsiOversold;
    /** @var float RSI 超买阈值（出场 LONG：RSI>rsiOverbought） */
    private $rsiOverbought;
    /** @var float 量能过滤阈值 = 当前量 > VOL_SMA × factor（常用 0.8：缩量跌破不接飞刀）*/
    private $volFilterFactor;

    public function __construct(
        int $bbPeriod = 20,
        float $bbStdMult = 2.0,
        int $rsiPeriod = 14,
        float $rsiOversold = 30.0,
        float $rsiOverbought = 65.0,
        float $volFilterFactor = 0.8
    ) {
        if ($bbPeriod < 2) {
            throw new \InvalidArgumentException("bbPeriod 至少为 2，给定 {$bbPeriod}");
        }
        if ($rsiPeriod < 2) {
            throw new \InvalidArgumentException("rsiPeriod 至少为 2，给定 {$rsiPeriod}");
        }
        $this->bbPeriod        = $bbPeriod;
        $this->bbStdMult       = $bbStdMult;
        $this->rsiPeriod       = $rsiPeriod;
        $this->rsiOversold     = $rsiOversold;
        $this->rsiOverbought   = $rsiOverbought;
        $this->volFilterFactor = $volFilterFactor;
    }

    public function getName(): string
    {
        return sprintf(
            'Bollinger(%d,%.1fσ) + RSI(%d,%.0f/%.0f) MeanReversion [v%s]',
            $this->bbPeriod,
            $this->bbStdMult,
            $this->rsiPeriod,
            $this->rsiOversold,
            $this->rsiOverbought,
            $this->version
        );
    }

    // ==============================================================
    //  第一步：populateIndicators（计算指标 → 写 5 列自定义扩展）
    // ==============================================================
    public function populateIndicators(array $matrix, TradingSymbol $symbol, string $timeframe): array
    {
        // 指标与 symbol/timeframe 无关（本策略是纯 price/volume 驱动）；保留参数以适配未来多周期策略
        unset($symbol, $timeframe);

        $n = count($matrix);
        if ($n === 0) {
            return $matrix;
        }

        // 1) 抽 close & volume
        $closeArr = [];
        $volArr   = [];
        for ($i = 0; $i < $n; $i++) {
            $closeArr[] = (float) $matrix[$i][SignalCols::CLOSE];
            $volArr[]   = (float) $matrix[$i][SignalCols::VOLUME];
        }

        // 2) 布林带（直接调用 trader 扩展的 bbands，返回 [upper, mid, lower]）
        //    注意：这里 mid = SMA(bbPeriod)，正好对应策略里的 COL_SMA20 语义
        [$bbUpper, $sma20, $bbLower] = IndicatorCalculator::bbands(
            $closeArr,
            $this->bbPeriod,
            $this->bbStdMult,
            $this->bbStdMult
        );

        // 3) RSI（PHP trader 扩展，Wilder's smoothing）
        $rsi14 = IndicatorCalculator::rsi($closeArr, $this->rsiPeriod);

        // 4) 成交量 SMA 20
        $volSma20 = IndicatorCalculator::sma($volArr, 20);

        // 5) 回填到矩阵扩展列
        for ($i = 0; $i < $n; $i++) {
            $matrix[$i][self::COL_SMA20]     = $sma20[$i];
            $matrix[$i][self::COL_BB_UPPER]  = $bbUpper[$i];
            $matrix[$i][self::COL_BB_LOWER]  = $bbLower[$i];
            $matrix[$i][self::COL_RSI14]     = $rsi14[$i];
            $matrix[$i][self::COL_VOL_SMA20] = $volSma20[$i];
        }
        return $matrix;
    }

    // ==============================================================
    //  第二步：populateEntryTrend（写 ENTER_LONG / ENTER_TAG）
    // ==============================================================
    public function populateEntryTrend(array $matrix): array
    {
        for ($i = 1, $n = count($matrix); $i < $n; $i++) {
            $close   = (float) $matrix[$i][SignalCols::CLOSE];
            $bbLower = (float) $matrix[$i][self::COL_BB_LOWER];
            $rsi     = (float) $matrix[$i][self::COL_RSI14];
            $vol     = (float) $matrix[$i][SignalCols::VOLUME];
            $volSma  = (float) $matrix[$i][self::COL_VOL_SMA20];

            // ---- 三条件同时满足：跌破下轨 + RSI<30 + 量够 ----
            $condBB   = $close <= $bbLower;                                   // 跌破下轨（或穿过）
            $condRSI  = $rsi < $this->rsiOversold;                             // RSI 确认超卖
            $condVol  = $volSma > 0 ? $vol >= $volSma * $this->volFilterFactor : true; // 量能过滤

            if ($condBB && $condRSI && $condVol) {
                $matrix[$i][SignalCols::ENTER_LONG] = SignalCols::SIG_NORMAL;
                $matrix[$i][SignalCols::ENTER_TAG]  = sprintf(
                    'bb_break(%.3f<%.3f)_rsi%.1f_vol%.2fx',
                    $close, $bbLower, $rsi,
                    $volSma > 0 ? round($vol / $volSma, 2) : 1.0
                );
            }
        }
        return $matrix;
    }

    // ==============================================================
    //  第三步：populateExitTrend（写 EXIT_LONG / EXIT_TAG）
    // ==============================================================
    public function populateExitTrend(array $matrix): array
    {
        for ($i = 1, $n = count($matrix); $i < $n; $i++) {
            $close  = (float) $matrix[$i][SignalCols::CLOSE];
            $bbMid  = (float) $matrix[$i][self::COL_SMA20];
            $rsi    = (float) $matrix[$i][self::COL_RSI14];

            // 回到中轨上方 + RSI > 超买阈值（均值回归"到位"）
            if ($close >= $bbMid && $rsi >= $this->rsiOverbought) {
                $matrix[$i][SignalCols::EXIT_LONG] = SignalCols::SIG_NORMAL;
                $matrix[$i][SignalCols::EXIT_TAG]  = sprintf(
                    'mean_reverted(%.3f>=%.3f)_rsi%.1f',
                    $close, $bbMid, $rsi
                );
            }
        }
        return $matrix;
    }

    // ==============================================================
    //  钩子：customExit —— "长时间横盘 + 没赚到钱就撤"
    // ==============================================================
    public function customExit(TradeRecord $trade, int $currentRowIndex, array $currentRow): bool
    {
        // 母类默认 HOLD 超时用 maxHoldBars 已经解决"硬超时"；这里处理"软超时"：
        // 持仓超过 60 根（5h）但浮盈 < 0.3%，说明行情没动，手续费都快磨没了 → 撤出。
        $openTs = $trade->getOpenTimestamp();
        $barTs  = (int) $currentRow[SignalCols::DATE];
        if ($openTs <= 0) {
            return false;
        }
        // 估算已持仓根数（按周期长度 300_000ms=5m）
        $barDurMs = 300_000;
        $barsHeld = $barDurMs > 0 ? (int) floor(($barTs - $openTs) / $barDurMs) : 0;
        if ($barsHeld >= 60) {
            $close = (float) $currentRow[SignalCols::CLOSE];
            $ratio = $trade->getUnrealizedProfitAbs($close) / max(1e-9, $trade->getStakeAmount());
            if ($ratio < 0.003) { // 浮盈不足 0.3%
                return true;
            }
        }
        return parent::customExit($trade, $currentRowIndex, $currentRow);
    }

    // 自定义入场价：挂到 BB 下轨（不追着下轨破位去成交，避免"接飞刀"）
    public function customEntryPrice(
        TradingSymbol $symbol,
        string $side,
        array $currentRow,
        array $previousRow
    ): ?float {
        // 例子：限价 BUY 挂到前一根 LOW × 0.999（更稳一点）
        return (float) $previousRow[SignalCols::LOW] * 0.999;
    }

    // ----- 读访问器（调试用，或报表输出指标列）-----
    public function getBbPeriod(): int { return $this->bbPeriod; }
    public function getRsiPeriod(): int { return $this->rsiPeriod; }
    public function getBbStdMult(): float { return $this->bbStdMult; }
}
