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
 *   出场 LONG：EMA(short) 下穿 EMA(long)
 *
 * 构造参数（可在 config/trader.php 的 construct 数组里覆盖）：
 *   int   $emaShort    短周期 EMA
 *   int   $emaLong     长周期 EMA（须 > short）
 *   float $filterPct   假信号过滤百分比（0.003 = 0.3%）
 */
class WmcStrategy extends AbstractStrategy
{
    public const COL_EMA_SHORT = SignalCols::NUM_COLUMNS + 0;
    public const COL_EMA_LONG  = SignalCols::NUM_COLUMNS + 1;

    private int $emaShortPeriod;
    private int $emaLongPeriod;
    private float $filterPct;

    // 风控（覆写父类属性）
    protected $stoploss       = 0.03;
    protected $minimalRoi     = [
        0   => 0.01,
        60  => 0.008,
        180 => 0.005,
        360 => 0,
    ];
    protected $trailingStop   = 0.0;
    protected $defaultStakeAmount = 1000.0;
    protected $maxOpenTrades = 10;
    protected $maxOpenTradesPerPair = 1;

    protected $version = '1.0';
    protected $description = 'EMA 金叉死叉策略';

    public function __construct(int $emaShort = 20, int $emaLong = 50, float $filterPct = 0.003)
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
