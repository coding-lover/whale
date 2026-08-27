<?php

namespace App\Services\Trader\Strategy;

/**
 * 信号列常量（对应 Freqtrade HEADERS / DATE_IDX 等索引）
 *
 * 策略三步法：
 *   populateIndicators → 附加 RSI/MACD/BB 等额外列
 *   populateEntryTrend → 写入 enter_long / enter_short 列：0=无，1=信号，2=强制入场（跳过保护）
 *   populateExitTrend  → 写入 exit_long / exit_short 列：0=无，1=出场信号
 *   另外 enter_tag / exit_tag 是字符串标签（用于报表分类："rsi<30"、"ema_cross"等）
 *
 * 本类用 11 个类常量代表列号，与 TradingSymbol / Candle 等都是 0 基数组对齐，
 * 方便 Backtesting 主编排器按下标做顺序遍历（性能比字符串 hash 表快得多，特别是百万级 K 线场景）。
 */
class SignalCols
{
    public const DATE        = 0; // 毫秒时间戳 int（与 Candle.timestamp 一致）
    public const OPEN        = 1;
    public const HIGH        = 2;
    public const LOW         = 3;
    public const CLOSE       = 4;
    public const VOLUME      = 5;
    public const ENTER_LONG  = 6; // int: 0/1/2 → 0 无、1 普通信号、2 强制入场
    public const EXIT_LONG   = 7;
    public const ENTER_SHORT = 8;
    public const EXIT_SHORT  = 9;
    public const ENTER_TAG   = 10; // string（空串表示无标签）
    public const EXIT_TAG    = 11;

    public const NUM_COLUMNS = 12;

    /** 信号强度阈值：≥1 就是入场/出场 */
    public const SIG_NONE     = 0;
    public const SIG_NORMAL   = 1;
    public const SIG_FORCE    = 2;

    /**
     * 把 12 列标准数组 + 自定义指标列展开成 assoc（供策略 populateIndicators 用）
     *
     * @param array<int, mixed> $row   12 列及更多列的一行
     * @return array<string, mixed>
     */
    public static function rowToAssoc(array $row): array
    {
        return [
            'date'        => $row[self::DATE],
            'open'        => $row[self::OPEN],
            'high'        => $row[self::HIGH],
            'low'         => $row[self::LOW],
            'close'       => $row[self::CLOSE],
            'volume'      => $row[self::VOLUME],
            'enter_long'  => $row[self::ENTER_LONG],
            'exit_long'   => $row[self::EXIT_LONG],
            'enter_short' => $row[self::ENTER_SHORT],
            'exit_short'  => $row[self::EXIT_SHORT],
            'enter_tag'   => $row[self::ENTER_TAG] ?? '',
            'exit_tag'    => $row[self::EXIT_TAG] ?? '',
        ];
    }

    /**
     * 从一组 Candle 构造标准 12 列矩阵（用于启动 populateIndicators 前）
     *
     * 为什么用固定列数组而不是 assoc？
     * - 内存更省：一整段连续内存（array<array> 而非 array<array> with string keys）
     * - 迭代更快：PHP VM 对数字下标访问更快
     * - 与 Freqtrade 的 `_get_ohlcv_as_lists` 理念一致
     *
     * @param \App\Services\Trader\Market\Candle[] $candles
     * @return array<int, array<int, mixed>> list of rows
     */
    public static function candlesToMatrix(array $candles): array
    {
        $matrix = [];
        foreach ($candles as $c) {
            $row = array_fill(0, self::NUM_COLUMNS, 0.0);
            $row[self::DATE]        = $c->getTimestamp();
            $row[self::OPEN]        = $c->getOpen();
            $row[self::HIGH]        = $c->getHigh();
            $row[self::LOW]         = $c->getLow();
            $row[self::CLOSE]       = $c->getClose();
            $row[self::VOLUME]      = $c->getVolume();
            $row[self::ENTER_LONG]  = 0;
            $row[self::EXIT_LONG]   = 0;
            $row[self::ENTER_SHORT] = 0;
            $row[self::EXIT_SHORT]  = 0;
            $row[self::ENTER_TAG]   = '';
            $row[self::EXIT_TAG]    = '';
            $matrix[] = $row;
        }
        return $matrix;
    }
}
