<?php

namespace App\Services\Trader\Enum;

/**
 * 保证金模式（合约/杠杆适用）
 */
class MarginMode
{
    public const NONE     = 'none';      // 现货
    public const ISOLATED = 'isolated';  // 逐仓
    public const CROSS    = 'cross';     // 全仓

    public static function all(): array
    {
        return [self::NONE, self::ISOLATED, self::CROSS];
    }
}
