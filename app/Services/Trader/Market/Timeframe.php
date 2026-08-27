<?php

namespace App\Services\Trader\Market;

use DateInterval;

/**
 * K 线时间周期（Timeframe）
 *
 * 与 CCXT / Binance / OKX 官方缩写保持一致：
 *   1m / 5m / 15m / 30m / 1h / 4h / 1d / 1w / 1M
 * 全部统一成一个字符串枚举类（PHP 7.4 没有原生 enum，用 const + 校验方法实现）
 */
class Timeframe
{
    public const TF_1M  = '1m';
    public const TF_3M  = '3m';
    public const TF_5M  = '5m';
    public const TF_15M = '15m';
    public const TF_30M = '30m';
    public const TF_1H  = '1h';
    public const TF_2H  = '2h';
    public const TF_4H  = '4h';
    public const TF_6H  = '6h';
    public const TF_8H  = '8h';
    public const TF_12H = '12h';
    public const TF_1D  = '1d';
    public const TF_3D  = '3d';
    public const TF_1W  = '1w';
    public const TF_1MO = '1M'; // 注意：月份用 1MO（避免和 1m 分钟的常量名冲突），对外仍显示 "1M"

    /** @var array<string, int> 每个周期对应的毫秒数 */
    private const MS_MAP = [
        self::TF_1M  => 60_000,
        self::TF_3M  => 180_000,
        self::TF_5M  => 300_000,
        self::TF_15M => 900_000,
        self::TF_30M => 1_800_000,
        self::TF_1H  => 3_600_000,
        self::TF_2H  => 7_200_000,
        self::TF_4H  => 14_400_000,
        self::TF_6H  => 21_600_000,
        self::TF_8H  => 28_800_000,
        self::TF_12H => 43_200_000,
        self::TF_1D  => 86_400_000,
        self::TF_3D  => 259_200_000,
        self::TF_1W  => 604_800_000,
        self::TF_1MO => 2_592_000_000, // 按 30 天近似（仅用于估算/步长推进）
    ];

    /**
     * 校验字符串是否是合法周期
     */
    public static function isValid(string $tf): bool
    {
        return isset(self::MS_MAP[$tf]);
    }

    /**
     * 把周期转成毫秒（用于按 K 线步进 + 日期对齐）
     *
     * @throws InvalidArgumentException 未知周期
     */
    public static function toMilliseconds(string $tf): int
    {
        if (!isset(self::MS_MAP[$tf])) {
            throw new \InvalidArgumentException("Unknown timeframe: {$tf}");
        }
        return self::MS_MAP[$tf];
    }

    /**
     * 返回 DateInterval（有些地方需要 PHP 原生日期计算）
     */
    public static function toDateInterval(string $tf): DateInterval
    {
        $map = [
            self::TF_1M  => 'PT1M',
            self::TF_3M  => 'PT3M',
            self::TF_5M  => 'PT5M',
            self::TF_15M => 'PT15M',
            self::TF_30M => 'PT30M',
            self::TF_1H  => 'PT1H',
            self::TF_2H  => 'PT2H',
            self::TF_4H  => 'PT4H',
            self::TF_6H  => 'PT6H',
            self::TF_8H  => 'PT8H',
            self::TF_12H => 'PT12H',
            self::TF_1D  => 'P1D',
            self::TF_3D  => 'P3D',
            self::TF_1W  => 'P1W',
            self::TF_1MO => 'P1M',
        ];
        if (!isset($map[$tf])) {
            throw new \InvalidArgumentException("Unknown timeframe: {$tf}");
        }
        return new DateInterval($map[$tf]);
    }

    /**
     * 把毫秒时间戳向下对齐到当前周期的起点（Binance/OKX 对齐标准）
     *
     * 例：5m 周期，ts = 2026-01-01 00:02:30.000 → 对齐到 00:00:00.000
     */
    public static function floorTimestamp(string $tf, int $timestampMs): int
    {
        $step = self::toMilliseconds($tf);
        return intdiv($timestampMs, $step) * $step;
    }
}
