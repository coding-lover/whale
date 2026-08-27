<?php

namespace App\Services\Trader\Enum;

/**
 * 订单类型：和 CCXT / 交易所接口一致（回测撮合规则也基于此）
 */
class OrderType
{
    public const LIMIT             = 'limit';              // 限价单
    public const MARKET            = 'market';             // 市价单
    public const STOP_LOSS         = 'stop_loss';          // 止损市价单（触发价突破后 market）
    public const STOP_LOSS_LIMIT   = 'stop_loss_limit';    // 止损限价单
    public const TAKE_PROFIT       = 'take_profit';        // 止盈市价单
    public const TAKE_PROFIT_LIMIT = 'take_profit_limit';  // 止盈限价单

    public static function all(): array
    {
        return [
            self::LIMIT,
            self::MARKET,
            self::STOP_LOSS,
            self::STOP_LOSS_LIMIT,
            self::TAKE_PROFIT,
            self::TAKE_PROFIT_LIMIT,
        ];
    }

    /**
     * 是否是"吃单"类型（maker 手续费 0.02%，taker 0.04%，手续费计算要区分）
     * - 市价单一定是 taker
     * - 止损/止盈触发后通常也是 taker（突破时才下单）
     * - 普通限价单用 maker 费率（除非开启了"立即或取消"这种特殊模式）
     */
    public static function isTaker(string $type): bool
    {
        return in_array($type, [self::MARKET, self::STOP_LOSS, self::TAKE_PROFIT], true);
    }
}
