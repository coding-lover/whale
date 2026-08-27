<?php

namespace App\Services\Trader\Enum;

/**
 * 订单状态（本系统用简化版）
 */
class OrderStatus
{
    public const OPEN     = 'open';      // 挂单中（未成交）
    public const CLOSED   = 'closed';    // 完全成交
    public const PARTIAL  = 'partial';   // 部分成交（撮合引擎会再创建补单）
    public const CANCELED = 'canceled';  // 已撤单
    public const EXPIRED  = 'expired';   // 已过期
    public const REJECTED = 'rejected';  // 被交易所拒单（价格精度/余额不足）

    public static function isTerminal(string $status): bool
    {
        // 终态：不再有后续动作（避免反复轮询它）
        return in_array($status, [self::CLOSED, self::CANCELED, self::EXPIRED, self::REJECTED], true);
    }
}
