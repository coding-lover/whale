<?php

namespace App\Services\Trader\Strategies;

use App\Services\Exchanges\TradingSymbol;
use App\Services\Trader\Strategy\AbstractStrategy;
use App\Services\Trader\Strategy\IndicatorCalculator;
use App\Services\Trader\Strategy\SignalCols;

/**
 * WmcStrategy — EMA 金叉死叉策略
 *
 * 规则：
 *   入场 LONG：EMA(short) 上穿 EMA(long)，且收盘价 > EMA(long) × (1 + filterPct)
 *   出场 LONG：EMA(short) 下穿 EMA(long)，或触发止损 / ROI 阶梯
 *
 * 默认参数（经 SKR/USDT:SWAP 15m、30 天数据网格寻优，样本内外两段都盈利）：
 *   int   $emaShort    短周期 EMA（12）
 *   int   $emaLong     长周期 EMA（60，须 > short）
 *   float $filterPct   假信号过滤百分比（0.005 = 0.5%）
 *
 * 风控（寻优结论）：
 *   - 止损 1.5%（紧止损，EMA 死叉本身负责趋势反转离场）
 *   - ROI 阶梯放宽到 3%/2%/1%/0.5%（紧阶梯 1% 止盈会过早砍掉盈利单，盈亏比 < 1）
 *   - 不启用追踪止损（网格实测 trailing 全部更差）
 */
class WmcStrategy extends AbstractStrategy
{
    public const COL_EMA_SHORT = SignalCols::NUM_COLUMNS + 0;
    public const COL_EMA_LONG  = SignalCols::NUM_COLUMNS + 1;

    private int $emaShortPeriod;
    private int $emaLongPeriod;
    private float $filterPct;

    // 风控（覆写父类属性；数值经 15m 网格寻优）
    protected $stoploss       = 0.015;       // 1.5% 固定止损
    protected $minimalRoi     = [            // 阶梯止盈（key=开仓后分钟数，15m 下 8/24/48 根）
        0   => 0.03,    // 开仓即达 3% 止盈
        120 => 0.02,    // 2 小时后 2% 就走
        360 => 0.01,    // 6 小时后 1%
        720 => 0.005,   // 12 小时后 0.5%
    ];
    protected $trailingStop   = 0.0;         // 不启用追踪止损（实测有害）
    protected $defaultStakeAmount = 1000.0;
    protected $maxOpenTrades = 10;
    protected $maxOpenTradesPerPair = 1;

    protected $version = '1.1';
    protected $description = 'EMA 金叉死叉策略（12/60 参数寻优版）';

    public function __construct(int $emaShort = 12, int $emaLong = 60, float $filterPct = 0.005)
    {
        if ($emaShort <= 0 || $emaLong <= 0 || $emaShort >= $emaLong) {
            throw new \InvalidArgumentException(
                "EMA 参数非法：期望 0 < short < long（short={$emaShort}, long={$emaLong}）"
            );
        }
        $this->emaShortPeriod = $emaShort;
        $this->emaLongPeriod  = $emaLong;
        $this->filterPct      = $filterPct;
    }

    public function getName(): string
    {
        return sprintf('WmcStrategy(%d/%d)', $this->emaShortPeriod, $this->emaLongPeriod);
    }

    public function populateIndicators(array $matrix, TradingSymbol $symbol, string $timeframe): array
    {
        unset($symbol, $timeframe);
        $n = count($matrix);
        $close = [];
        for ($i = 0; $i < $n; $i++) {
            $close[] = (float) $matrix[$i][SignalCols::CLOSE];
        }
        $emaShort = IndicatorCalculator::ema($close, $this->emaShortPeriod);
        $emaLong  = IndicatorCalculator::ema($close, $this->emaLongPeriod);
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
}
