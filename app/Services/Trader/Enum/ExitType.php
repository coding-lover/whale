<?php

namespace App\Services\Trader\Enum;

/**
 * 平仓原因（ExitType）——对应 Freqtrade 的 ExitType 枚举
 *
 * 顺序代表优先级：撮合器 `_check_trade_exit` 里按这个顺序判断，先命中的先平仓。
 * 顺序不可随意调整！
 */
class ExitType
{
    public const NONE              = 'none';
    public const LIQUIDATION       = 'liquidation';       // 强平（保证金不够，优先级最高）
    public const STOP_LOSS         = 'stop_loss';         // 固定止损
    public const TRAILING_STOP     = 'trailing_stop';     // 移动止损
    public const ROI               = 'roi';               // 最小 ROI 表（阶梯止盈）
    public const EXIT_SIGNAL       = 'exit_signal';       // 策略 exit_long/exit_short=1
    public const CUSTOM_EXIT       = 'custom_exit';       // 策略 custom_exit() 回调
    public const FORCE_EXIT        = 'force_exit';        // 强制平仓（回测时间到、保护冷却期、CLI 手动关闭）
    public const STOP_ON_TIMEOUT   = 'stop_on_timeout';   // 达到 HOLD 上限，超时强制平

    /**
     * 有效的"平仓原因"集合（在 Trade.close_reason 字段记录时必须属于这里）
     */
    public static function allExitReasons(): array
    {
        return [
            self::LIQUIDATION,
            self::STOP_LOSS,
            self::TRAILING_STOP,
            self::ROI,
            self::EXIT_SIGNAL,
            self::CUSTOM_EXIT,
            self::FORCE_EXIT,
            self::STOP_ON_TIMEOUT,
        ];
    }
}
