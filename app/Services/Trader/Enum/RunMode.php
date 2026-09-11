<?php

namespace App\Services\Trader\Enum;

/**
 * 运行模式（决定撮合器是否落库/是否用真实行情/是否真的下单）
 */
class RunMode
{
    public const BACKTEST = 'backtest'; // 纯历史数据 + 内存撮合，不连交易所
    public const DRY_RUN  = 'dry_run';  // 连交易所真实行情，但订单存在内存（模拟单）
    public const LIVE     = 'live';     // 实盘：真下单

    public static function all(): array
    {
        return [self::BACKTEST, self::DRY_RUN, self::LIVE];
    }

    public static function isValid(string $mode): bool
    {
        return in_array($mode, self::all(), true);
    }
}
