<?php

namespace App\Services\Trader\ExitRules;

use App\Services\Trader\Enum\ExitType;
use App\Services\Trader\Model\TradeRecord;

/**
 * 平仓规则检查器（ExitRules）
 *
 * Freqtrade _check_trade_exit 中 5 种规则集中在这里：
 *   LIQUIDATION（强平）→ STOP_LOSS（固定止损）→ TRAILING_STOP（追踪止损）
 *   → ROI（阶梯止盈）→ STOP_ON_TIMEOUT（HOLD 超时）
 *
 * 策略层的 exit_signal / custom_exit 检查在 Backtesting 中处理，不属本类。
 */
class ExitRules
{
    /**
     * 检查是否需要平仓
     *
     * @param TradeRecord $trade
     * @param array       $currentRow  当前 K 线行（SignalCols 12 列）
     * @param int         $barDurationMs 每根 K 线毫秒数
     * @param float       $stoplossPct 小数，0 = 不启用
     * @param array<int, float> $minimalRoi 开仓分钟 → 目标收益率小数
     * @param float       $trailingStopPct
     * @param float       $trailingStopActivate 激活阈值小数，达到后才启动 trailing
     * @param int         $maxHoldBars 最大持仓根 K 线
     * @return array{0:string,1:float}|null [ExitType, 触发价]
     */
    public function check(
        TradeRecord $trade,
        array $currentRow,
        int $barDurationMs,
        float $stoplossPct,
        array $minimalRoi,
        float $trailingStopPct,
        float $trailingStopActivate,
        int $maxHoldBars
    ): ?array {
        $high  = (float) $currentRow[\App\Services\Trader\Strategy\SignalCols::HIGH];
        $low   = (float) $currentRow[\App\Services\Trader\Strategy\SignalCols::LOW];
        $close = (float) $currentRow[\App\Services\Trader\Strategy\SignalCols::CLOSE];
        $nowTs = (int)   $currentRow[\App\Services\Trader\Strategy\SignalCols::DATE];

        // 1. 强平
        $liqPrice = $trade->getLiquidationPrice();
        if ($liqPrice > 0) {
            if (($trade->isLong() && $low <= $liqPrice) || ($trade->isShort() && $high >= $liqPrice)) {
                return [ExitType::LIQUIDATION, $liqPrice];
            }
        }

        // 2. 固定止损
        if ($stoplossPct > 0) {
            $open = $trade->getOpenRate();
            if ($open > 0) {
                if ($trade->isLong()) {
                    $stopPrice = $open * (1 - $stoplossPct);
                    if ($low <= $stopPrice) {
                        return [ExitType::STOP_LOSS, $stopPrice];
                    }
                } else {
                    $stopPrice = $open * (1 + $stoplossPct);
                    if ($high >= $stopPrice) {
                        return [ExitType::STOP_LOSS, $stopPrice];
                    }
                }
            }
        }

        // 3. 追踪止损（trade.updateExtremesAndTrailing 已维护 trailingStopPrice）
        if ($trailingStopPct > 0) {
            $tsPrice = $trade->getTrailingStopPrice();
            if ($tsPrice !== null) {
                $activated = true;
                if ($trailingStopActivate > 0) {
                    $unrealPct = $this->calcUnrealizedProfitRatio($trade, $close);
                    if ($unrealPct < $trailingStopActivate) {
                        $activated = false;
                    }
                }
                if ($activated) {
                    if ($trade->isLong() && $low <= $tsPrice) {
                        return [ExitType::TRAILING_STOP, $tsPrice];
                    }
                    if (!$trade->isLong() && $high >= $tsPrice) {
                        return [ExitType::TRAILING_STOP, $tsPrice];
                    }
                }
            }
        }

        // 4. Minimal ROI 阶梯止盈
        if ($minimalRoi !== []) {
            $openTs         = $trade->getOpenTimestamp();
            $elapsedMinutes = $openTs > 0 ? max(0, (int) floor(($nowTs - $openTs) / 60_000)) : 0;
            $target         = $this->resolveRoiTarget($minimalRoi, $elapsedMinutes);
            if ($target !== null) {
                $unrealPct = $this->calcUnrealizedProfitRatio($trade, $close);
                if ($unrealPct >= $target) {
                    return [ExitType::ROI, $close];
                }
            }
        }

        // 5. HOLD 超时
        if ($maxHoldBars > 0 && $trade->getOpenTimestamp() > 0 && $barDurationMs > 0) {
            $openTs      = $trade->getOpenTimestamp();
            $elapsedBars = (int) ceil(($nowTs - $openTs) / $barDurationMs);
            if ($elapsedBars >= $maxHoldBars) {
                return [ExitType::STOP_ON_TIMEOUT, $close];
            }
        }

        return null;
    }

    /**
     * 在 ROI 阶梯表中找到"当前已过分钟数"对应的目标收益率
     *
     * 例：[0 => 0.10, 30 => 0.05, 120 => 0.02, 240 => 0] 经过 40 分钟 → 返回 0.05
     */
    public function resolveRoiTarget(array $roiTable, int $elapsedMinutes): ?float
    {
        if ($roiTable === []) {
            return null;
        }
        ksort($roiTable, SORT_NUMERIC);
        $target = null;
        foreach ($roiTable as $minute => $pct) {
            if ($elapsedMinutes >= (int) $minute) {
                $target = (float) $pct;
            } else {
                break;
            }
        }
        return $target;
    }

    private function calcUnrealizedProfitRatio(TradeRecord $trade, float $markRate): float
    {
        $stake = $trade->getStakeAmount();
        if ($stake <= 0) {
            return 0.0;
        }
        return $trade->getUnrealizedProfitAbs($markRate) / $stake;
    }
}
