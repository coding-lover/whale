<?php

namespace App\Services\Trader;

/**
 * 回测绩效报告（PerformanceReport）
 *
 * 输入：BacktestResult（trade list + equity curve + initial/final balance）
 * 输出：30+ 指标（年化收益、夏普、索提诺、卡玛、最大回撤、胜率、盈亏比等）
 *
 * 公式全部按行业通用定义，与 Freqtrade `generate_backtest_stats` 口径尽量对齐：
 *   Sharpe     = mean(daily_return) / std(daily_return) × sqrt(365)   （加密按 365 天，非股市 252）
 *   Sortino    = mean(R) / std(negative daily_R) × sqrt(365)
 *   Calmar     = CAGR / max_drawdown_abs
 *   胜率       = 盈利交易数 / 总交易数
 *   盈亏比     = 平均盈利 / 平均亏损（绝对值）
 *
 * 所有指标算法使用 PHP 原生数学，避免外部依赖，计算精度满足报告用途。
 */
class PerformanceReport
{
    /** @var array<string, float|int|string|null> 保存结果 */
    private $metrics = [];

    public function __construct(BacktestResult $result, float $initialCapital, int $tradingDaysPerYear = 365)
    {
        $this->metrics = $this->computeAll($result, $initialCapital, $tradingDaysPerYear);
    }

    /**
     * 获取单个指标
     *
     * @return float|int|string|null
     */
    public function get(string $key)
    {
        return $this->metrics[$key] ?? null;
    }

    /**
     * 返回全部指标
     *
     * @return array<string, float|int|string|null>
     */
    public function all(): array
    {
        return $this->metrics;
    }

    /**
     * @return array<string, float|int|string|null>
     */
    private function computeAll(BacktestResult $result, float $initialCapital, int $days): array
    {
        $trades = $result->getTrades();
        $curve  = $result->getEquityCurve();
        $closed = [];
        foreach ($trades as $t) {
            if (empty($t['is_open'])) {
                $closed[] = $t;
            }
        }
        $finalBal   = $result->getFinalBalance();
        $finalBal   = $finalBal > 0 ? $finalBal : $this->curveTotalAtEnd($curve, $initialCapital);
        $startTs    = $this->firstTs($curve);
        $endTs      = $this->lastTs($curve);
        $durationY  = $endTs > $startTs ? max(1 / 365, ($endTs - $startTs) / 86_400_000 / 365) : 1 / $days;

        $totalReturnPct = $initialCapital > 0 ? (($finalBal - $initialCapital) / $initialCapital) : 0.0;
        $cagr           = $initialCapital > 0 && $finalBal > 0
            ? (pow($finalBal / $initialCapital, 1 / $durationY) - 1)
            : 0.0;

        // --- 权益曲线：转日收益率序列 ---
        $dailyReturns = $this->extractDailyReturns($curve, $initialCapital, $days === 365);

        $sharpe = $this->sharpe($dailyReturns, $days);
        $sortino = $this->sortino($dailyReturns, $days);

        // --- 最大回撤 ---
        $mdd = $this->maxDrawdown($curve, $initialCapital);

        $calmar = ($mdd['drawdown_pct'] ?? 0) > 0 ? $cagr / (float) $mdd['drawdown_pct'] : INF;

        // --- 交易统计 ---
        $stats = $this->tradeStats($closed);

        return [
            // 基础
            'strategy'           => $result->getStrategyName(),
            'strategy_version'   => $result->getStrategyVersion(),
            'trading_mode'       => $result->getTradingMode(),
            'stake_currency'     => $result->getStakeCurrency(),
            'timeframe'          => $result->getTimeframe(),
            'backtest_start_iso' => $startTs ? gmdate('c', (int) ($startTs / 1000)) : null,
            'backtest_end_iso'   => $endTs   ? gmdate('c', (int) ($endTs   / 1000)) : null,
            'duration_years'     => round($durationY, 4),
            // 收益
            'initial_capital'    => $initialCapital,
            'final_capital'      => round($finalBal, 8),
            'total_net_profit'   => round($finalBal - $initialCapital, 8),
            'total_return_pct'   => round($totalReturnPct * 100, 4),     // %
            'cagr_pct'           => round($cagr * 100, 4),                // %
            // 风险
            'sharpe_ratio'       => is_finite($sharpe)  ? round($sharpe, 4)  : null,
            'sortino_ratio'      => is_finite($sortino) ? round($sortino, 4) : null,
            'calmar_ratio'       => is_finite($calmar)  ? round($calmar, 4)  : null,
            'max_drawdown_pct'   => round(($mdd['drawdown_pct'] ?? 0.0) * 100, 4),
            'max_drawdown_abs'   => round($mdd['drawdown_abs'] ?? 0.0, 8),
            'max_drawdown_start' => $mdd['start_iso'] ?? null,
            'max_drawdown_end'   => $mdd['end_iso']   ?? null,
            'max_drawdown_peak'  => $mdd['peak_iso']  ?? null,
            // 交易
            'total_trades'       => count($closed),
            'signals_total'      => $result->getSignalsTotal(),
            'signals_rejected'   => $result->getRejectedSignals(),
            'win_count'          => $stats['win_count'],
            'loss_count'         => $stats['loss_count'],
            'win_rate_pct'       => $stats['win_rate_pct'],
            'avg_trade_profit_pct'=> $stats['avg_profit_pct'],
            'avg_win_profit_pct' => $stats['avg_win_pct'],
            'avg_loss_profit_pct'=> $stats['avg_loss_pct'],
            'profit_factor'      => $stats['profit_factor'],
            'profit_loss_ratio'  => $stats['pl_ratio'],
            'best_trade_pct'     => $stats['best_pct'],
            'worst_trade_pct'    => $stats['worst_pct'],
            'avg_duration_min'   => $stats['avg_duration_min'],
            'expectancy_per_trade_abs' => $stats['expectancy_abs'],
        ];
    }

    // ---------- 内部算法 ----------

    /**
     * 提取日收益率（每日 close-to-close 对数近似 → 用简单算术收益率，行业最通用）
     *
     * @return float[] 每日收益率小数（1% = 0.01），长度 = n_days - 1
     */
    private function extractDailyReturns(array $equityCurve, float $initial, bool $useDayMs): array
    {
        if ($equityCurve === []) {
            return [];
        }
        $dayMs = 86_400_000;
        // 用 UTC 日键聚合：每个自然日取最后一笔快照作为当日净值
        $daily = [];
        foreach ($equityCurve as $snap) {
            $ts  = (int) $snap['timestamp'];
            $key = (int) floor($ts / $dayMs);
            $daily[$key] = (float) $snap['total'];
        }
        ksort($daily);
        $values = array_values($daily);
        // 至少插入 initial 作为第 0 天（如果曲线第一根之后有 initial 对应第一天，也会被替换为真实）
        if ($values === [] || abs($values[0] - $initial) > 0.01) {
            array_unshift($values, $initial);
        }
        $returns = [];
        for ($i = 1, $n = count($values); $i < $n; $i++) {
            if ($values[$i - 1] <= 0) {
                continue;
            }
            $r = ($values[$i] - $values[$i - 1]) / $values[$i - 1];
            if (is_finite($r)) {
                $returns[] = $r;
            }
        }
        unset($useDayMs);
        return $returns;
    }

    /**
     * Sharpe = mean(R) / std(R) * sqrt(days)，如果 std≈0 返回 0
     */
    private function sharpe(array $returns, int $daysPerYear): float
    {
        $n = count($returns);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($returns) / $n;
        $variance = 0.0;
        foreach ($returns as $r) {
            $variance += ($r - $mean) ** 2;
        }
        $variance /= ($n - 1); // 样本无偏
        $std = sqrt($variance);
        if ($std < 1e-12) {
            return 0.0;
        }
        return ($mean / $std) * sqrt($daysPerYear);
    }

    /**
     * Sortino：分母只用"下行波动率"（即负收益率的标准差，把 >0 的视为 0）
     */
    private function sortino(array $returns, int $daysPerYear): float
    {
        $n = count($returns);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($returns) / $n;
        $downside = [];
        foreach ($returns as $r) {
            if ($r < 0) {
                $downside[] = $r * $r;
            } else {
                $downside[] = 0.0;
            }
        }
        $varDown = array_sum($downside) / ($n - 1);
        $stdDown = sqrt($varDown);
        if ($stdDown < 1e-12) {
            return 0.0;
        }
        return ($mean / $stdDown) * sqrt($daysPerYear);
    }

    /**
     * 最大回撤（MDD）：从 equity 曲线精确找出 peak → 下一个最低 valley 区间
     *
     * @return array{drawdown_abs:float,drawdown_pct:float,peak_ts:int|null,start_ts:int|null,end_ts:int|null,peak_iso:string|null,start_iso:string|null,end_iso:string|null}
     */
    private function maxDrawdown(array $equityCurve, float $initial): array
    {
        $result = [
            'drawdown_abs'  => 0.0,
            'drawdown_pct'  => 0.0,
            'peak_ts'       => null,
            'start_ts'      => null,
            'end_ts'        => null,
            'peak_iso'      => null,
            'start_iso'     => null,
            'end_iso'       => null,
        ];
        if ($equityCurve === []) {
            return $result;
        }
        $peak = $initial;
        $peakTs = null;
        $startTs = null;
        $ddAbs = 0.0;
        $ddPct = 0.0;
        $endTs = null;
        foreach ($equityCurve as $snap) {
            $total = (float) $snap['total'];
            $ts    = (int)   $snap['timestamp'];
            if ($total >= $peak) {
                $peak   = $total;
                $peakTs = $ts;
                $startTs = $ts; // 下一次回撤的起点就是新高
                continue;
            }
            $abs = $peak - $total;
            $pct = $peak > 0 ? $abs / $peak : 0.0;
            if ($pct > $ddPct) {
                $ddAbs   = $abs;
                $ddPct   = $pct;
                $endTs   = $ts;
                $result['start_ts'] = $startTs ?? $peakTs;
                $result['peak_ts']  = $peakTs;
            }
        }
        $result['drawdown_abs'] = $ddAbs;
        $result['drawdown_pct'] = $ddPct;
        $result['end_ts']       = $endTs;
        foreach (['peak_ts', 'start_ts', 'end_ts'] as $k) {
            if ($result[$k] !== null) {
                $isoK = str_replace('_ts', '_iso', $k);
                $result[$isoK] = gmdate('c', (int) ($result[$k] / 1000));
            }
        }
        return $result;
    }

    /**
     * 单笔交易统计
     *
     * @param array<int, array<string, mixed>> $closed 已平仓交易
     * @return array<string, float|int|null>
     */
    private function tradeStats(array $closed): array
    {
        $winCount  = 0;
        $lossCount = 0;
        $winSumAbs  = 0.0;
        $lossSumAbs = 0.0;
        $winPctSum  = 0.0;
        $lossPctSum = 0.0;
        $allAbsSum  = 0.0;
        $allPctSum  = 0.0;
        $bestPct    = null;
        $worstPct   = null;
        $durMinSum  = 0;

        foreach ($closed as $t) {
            $absProfit = (float) ($t['close_profit_abs']   ?? 0.0);
            $pctProfit = (float) ($t['close_profit_ratio'] ?? 0.0) * 100; // 转成 % 方便读
            $durMin    = (int)   ($t['duration_minutes']   ?? 0);

            $allAbsSum += $absProfit;
            $allPctSum += $pctProfit;
            $durMinSum += $durMin;

            if ($bestPct === null || $pctProfit > $bestPct) {
                $bestPct = $pctProfit;
            }
            if ($worstPct === null || $pctProfit < $worstPct) {
                $worstPct = $pctProfit;
            }

            if ($absProfit > 0) {
                $winCount++;
                $winSumAbs += $absProfit;
                $winPctSum += $pctProfit;
            } else {
                $lossCount++;
                $lossSumAbs += abs($absProfit);
                $lossPctSum += abs($pctProfit);
            }
        }
        $n = count($closed);
        $winRate = $n > 0 ? $winCount / $n * 100 : null;
        $avgProfit = $n > 0 ? $allPctSum / $n : 0.0;
        $avgWinPct = $winCount  > 0 ? $winPctSum  / $winCount  : 0.0;
        $avgLossPct = $lossCount > 0 ? $lossPctSum / $lossCount : 0.0;
        $plRatio  = $avgLossPct > 1e-12 ? $avgWinPct / $avgLossPct : null;
        $pf       = $lossSumAbs > 1e-12 ? $winSumAbs / $lossSumAbs : 0.0;
        $expectancy = $n > 0 ? $allAbsSum / $n : 0.0;
        $avgDur = $n > 0 ? round($durMinSum / $n, 2) : 0;

        return [
            'win_count'          => $winCount,
            'loss_count'         => $lossCount,
            'win_rate_pct'       => $winRate !== null ? round($winRate, 2) : null,
            'avg_profit_pct'     => round($avgProfit, 4),
            'avg_win_pct'        => round($avgWinPct, 4),
            'avg_loss_pct'       => round($avgLossPct, 4),
            'pl_ratio'           => $plRatio !== null ? round($plRatio, 4) : null,
            'profit_factor'      => round($pf, 4),
            'best_pct'           => $bestPct  !== null ? round($bestPct,  4) : null,
            'worst_pct'          => $worstPct !== null ? round($worstPct, 4) : null,
            'avg_duration_min'   => $avgDur,
            'expectancy_abs'     => round($expectancy, 8),
        ];
    }

    /** @return int|null */
    private function firstTs(array $curve)
    {
        if ($curve === []) {
            return null;
        }
        return (int) $curve[0]['timestamp'];
    }

    /** @return int|null */
    private function lastTs(array $curve)
    {
        if ($curve === []) {
            return null;
        }
        return (int) $curve[count($curve) - 1]['timestamp'];
    }

    private function curveTotalAtEnd(array $curve, float $fallback): float
    {
        if ($curve === []) {
            return $fallback;
        }
        return (float) $curve[count($curve) - 1]['total'];
    }
}
