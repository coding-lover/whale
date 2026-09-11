<?php

namespace App\Services\Trader;

/**
 * 回测结果导出器
 *
 *  - toArray()   : 扁平数组（CLI 打印、日志、API 返回、存入 DB JSON 字段）
 *  - toJson()    : JSON 字符串（Web 接口、文件落盘）
 *  - toCsvRows() : 交易明细 CSV 行数组（Excel 友好）
 */
class ResultExporter
{
    /**
     * 把 BacktestResult + PerformanceReport 合并成扁平数组
     *
     * @param BacktestResult     $result
     * @param PerformanceReport  $perf
     * @return array<string, mixed>
     */
    public function toArray(BacktestResult $result, PerformanceReport $perf): array
    {
        return [
            'summary'     => $perf->all(),
            'trades'      => $result->getTrades(),
            'equity_curve'=> $result->getEquityCurve(),
            'meta'        => [
                'signals_total'    => $result->getSignalsTotal(),
                'signals_rejected' => $result->getRejectedSignals(),
                'final_balance'    => $result->getFinalBalance(),
                'trade_count'      => $result->getTradeCount(),
                'stake_currency'   => $result->getStakeCurrency(),
            ],
        ];
    }

    public function toJson(BacktestResult $result, PerformanceReport $perf, bool $pretty = true): string
    {
        $flags = $pretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                         : JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $encoded = json_encode($this->toArray($result, $perf), $flags);
        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * 交易明细转 CSV（返回 [headerRow, ...dataRows]）
     *
     * @return array<int, array<int, mixed>>
     */
    public function toCsvRows(BacktestResult $result): array
    {
        $header = [
            'trade_id', 'pair', 'direction', 'trading_mode', 'leverage',
            'stake_amount', 'amount', 'open_rate', 'close_rate',
            'open_date_utc', 'close_date_utc', 'duration_min',
            'close_reason', 'enter_tag', 'exit_tag',
            'fee_open', 'fee_close', 'funding_interest',
            'min_rate', 'max_rate',
            'nr_entries', 'nr_exits',
            'profit_abs', 'profit_pct',
        ];
        $rows = [$header];
        foreach ($result->getTrades() as $t) {
            $rows[] = [
                $t['trade_id'],
                $t['pair'],
                $t['direction'],
                $t['trading_mode'],
                $t['leverage'],
                $t['stake_amount'],
                $t['amount'],
                $t['open_rate'],
                $t['close_rate'],
                $t['open_date_utc'],
                $t['close_date_utc'],
                $t['duration_minutes'],
                $t['close_reason'],
                $t['enter_tag'],
                $t['exit_tag'],
                $t['fee_open'],
                $t['fee_close'],
                $t['funding_interest'],
                $t['min_rate'],
                $t['max_rate'],
                $t['nr_entries'],
                $t['nr_exits'],
                $t['close_profit_abs'],
                round(($t['close_profit_ratio'] ?? 0) * 100, 4),
            ];
        }
        return $rows;
    }

    /**
     * 写 CSV 到文件
     *
     * @param string $path
     * @param array<int, array<int, mixed>> $csvRows 来自 toCsvRows
     */
    public function writeCsvFile(string $path, array $csvRows): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fp = fopen($path, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Cannot write CSV file: {$path}");
        }
        try {
            foreach ($csvRows as $row) {
                fputcsv($fp, $row);
            }
        } finally {
            fclose($fp);
        }
    }
}
