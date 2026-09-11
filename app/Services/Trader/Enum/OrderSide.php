<?php

namespace App\Services\Trader\Enum;

/**
 * 订单方向：买 / 卖（注意这是订单侧概念，不是持仓方向）
 * - long 开仓 → side=BUY
 * - long 平仓 → side=SELL
 * - short 开仓 → side=SELL
 * - short 平仓 → side=BUY
 * 这和 Freqtrade 的 ft_order_side 一致，避免混淆"入场=买"的错误。
 */
class OrderSide
{
    public const BUY  = 'buy';
    public const SELL = 'sell';

    public static function opposite(string $side): string
    {
        if ($side === self::BUY) {
            return self::SELL;
        }
        if ($side === self::SELL) {
            return self::BUY;
        }
        throw new \InvalidArgumentException("Invalid order side: {$side}");
    }

    public static function all(): array
    {
        return [self::BUY, self::SELL];
    }
}
