<?php

namespace App\Services\Trader\Strategies;

use App\Services\Exchanges\TradingSymbol;
use App\Services\Trader\Model\TradeRecord;
use App\Services\Trader\Strategy\AbstractStrategy;
use App\Services\Trader\Strategy\SignalCols;

/**
 * EMA 金叉死叉示例策略（教学参考）
 *
 * 规则：
 *  入场 LONG：EMA(short) 上穿 EMA(long)，且收盘价 > EMA(long) × (1 + filter_pct) 过滤假信号
 *  出场：     EMA(short) 下穿 EMA(long)，或止损/ROI（按 strategy 配置）
 *
 *  注意：本策略仅作 DEMO。真实市场里裸 EMA 交叉表现通常很差。
 *
 * 使用：
 *   $s = new EmaCrossStrategy(20, 50);
 *   // 默认止损 3%，ROI 阶梯 [0=>0.03, 60=>0.02, 180=>0.01, 360=>0]
 */
class EmaCrossStrategy extends AbstractStrategy
{
    /** @var int 自定义列下标：短周期 EMA */
    public const COL_EMA_SHORT = SignalCols::NUM_COLUMNS + 0;
    /** @var int 自定义列下标：长周期 EMA */
    public const COL_EMA_LONG  = SignalCols::NUM_COLUMNS + 1;

    /** @var int */
    private $emaShortPeriod;
    /** @var int */
    private $emaLongPeriod;
    /** @var float 过滤假信号：收盘价需要高于 EMA_LONG 多少才允许入场（0.003 = 0.3%） */
    private $filterPct;

    // 默认止损/ROI（覆写父类属性）
    protected $stoploss       = 0.03;        // 3% 止损
    protected $minimalRoi     = [            // 阶梯止盈
        0   => 0.03,   // 开仓即止盈 3%
        60  => 0.02,   // 1 小时后 2% 就走
        180 => 0.01,   // 3 小时后 1%
        360 => 0,      // 6 小时后盈亏都平
    ];
    protected $trailingStop   = 0.0;         // 本例不启用 trailing
    protected $defaultStakeAmount = 500.0;   // 每笔 500 USDT
    protected $maxOpenTrades = 5;
    protected $maxOpenTradesPerPair = 1;

    protected $version = '1.0-demo';
    protected $description = 'EMA 金叉死叉，仅供教学示例';

    public function __construct(int $emaShort = 20, int $emaLong = 50, float $filterPct = 0.003)
    {
        if ($emaShort <= 0 || $emaLong <= 0 || $emaShort >= $emaLong) {
            throw new \InvalidArgumentException(
                "EMA 参数非法：期望 0<short<long (给定 short={$emaShort}, long={$emaLong})"
            );
        }
        $this->emaShortPeriod = $emaShort;
        $this->emaLongPeriod  = $emaLong;
        $this->filterPct      = $filterPct;
    }

    public function getName(): string
    {
        return sprintf('EmaCross(%d/%d)', $this->emaShortPeriod, $this->emaLongPeriod);
    }

    public function populateIndicators(array $matrix, TradingSymbol $symbol, string $timeframe): array
    {
        unset($symbol, $timeframe); // 本例 pair/tf 无关
        $n = count($matrix);
        // 1) 抽 close 列
        $closeArr = [];
        for ($i = 0; $i < $n; $i++) {
            $closeArr[] = (float) $matrix[$i][SignalCols::CLOSE];
        }
        // 2) 计算 EMA short/long
        $emaShort = self::ema($closeArr, $this->emaShortPeriod);
        $emaLong  = self::ema($closeArr, $this->emaLongPeriod);
        // 3) 写回矩阵扩展列
        for ($i = 0; $i < $n; $i++) {
            $matrix[$i][self::COL_EMA_SHORT] = $emaShort[$i];
            $matrix[$i][self::COL_EMA_LONG]  = $emaLong[$i];
        }
        return $matrix;
    }

    public function populateEntryTrend(array $matrix): array
    {
        for ($i = 1, $n = count($matrix); $i < $n; $i++) {
            $prevShort = $matrix[$i - 1][self::COL_EMA_SHORT];
            $prevLong  = $matrix[$i - 1][self::COL_EMA_LONG];
            $curShort  = $matrix[$i][self::COL_EMA_SHORT];
            $curLong   = $matrix[$i][self::COL_EMA_LONG];
            $close     = (float) $matrix[$i][SignalCols::CLOSE];
            if ($prevShort <= $prevLong && $curShort > $curLong
                && $close > $curLong * (1 + $this->filterPct)
            ) {
                $matrix[$i][SignalCols::ENTER_LONG] = SignalCols::SIG_NORMAL;
                $matrix[$i][SignalCols::ENTER_TAG]  = sprintf(
                    'ema_cross_up(close=%.2f,emaS=%.2f,emaL=%.2f)',
                    $close, $curShort, $curLong
                );
            }
        }
        return $matrix;
    }

    public function populateExitTrend(array $matrix): array
    {
        for ($i = 1, $n = count($matrix); $i < $n; $i++) {
            $prevShort = $matrix[$i - 1][self::COL_EMA_SHORT];
            $prevLong  = $matrix[$i - 1][self::COL_EMA_LONG];
            $curShort  = $matrix[$i][self::COL_EMA_SHORT];
            $curLong   = $matrix[$i][self::COL_EMA_LONG];
            if ($prevShort >= $prevLong && $curShort < $curLong) {
                $matrix[$i][SignalCols::EXIT_LONG] = SignalCols::SIG_NORMAL;
                $matrix[$i][SignalCols::EXIT_TAG]  = 'ema_cross_down';
            }
        }
        return $matrix;
    }

    // --------------------------
    //  Helpers: EMA（Wilder alpha = 2/(period+1)，标准）
    // --------------------------

    /**
     * 计算 EMA，长度和输入保持一致
     *   - 前 period-1 根保持前 period 的 SMA 作为种子（与 ta-lib / TradingView 行为最接近，避免 jump）
     *   - 之后用 EMA 递推
     *
     * @param float[] $src
     * @return float[]
     */
    public static function ema(array $src, int $period): array
    {
        $n = count($src);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0 || $period <= 0) {
            return $out;
        }
        $k = 2 / ($period + 1);
        // 种子 SMA
        $seed = 0.0;
        $seedLen = min($period, $n);
        for ($i = 0; $i < $seedLen; $i++) {
            $seed += $src[$i];
        }
        $seed /= $seedLen;
        $ema = $seed;
        for ($i = 0; $i < $seedLen; $i++) {
            $out[$i] = $ema;
        }
        for ($i = $seedLen; $i < $n; $i++) {
            $ema = $src[$i] * $k + $ema * (1 - $k);
            $out[$i] = $ema;
        }
        return $out;
    }

    // customExit 等钩子不用覆写（AbstractStrategy 提供默认 no-op）
}
