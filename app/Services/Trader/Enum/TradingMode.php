<?php

namespace App\Services\Trader\Enum;

/**
 * 交易模式：现货 / 逐仓 / 全仓
 */
class TradingMode
{
    public const SPOT    = 'spot';
    public const MARGIN  = 'margin';    // 币本位/现货杠杆
    public const FUTURES = 'futures';   // U本位合约

    public static function all(): array
    {
        return [self::SPOT, self::MARGIN, self::FUTURES];
    }

    public static function isValid(string $mode): bool
    {
        return in_array($mode, self::all(), true);
    }

    /**
     * 是否是可以做空的市场（现货只能 long）
     */
    public static function supportsShort(string $mode): bool
    {
        return $mode === self::MARGIN || $mode === self::FUTURES;
    }
}
