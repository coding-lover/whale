<?php

namespace App\Services\Trader\Fee;

use App\Services\Trader\Enum\OrderType;
use InvalidArgumentException;

/**
 * 手续费计算器
 *
 * 与 Freqtrade 一致，按订单类型区分 maker / taker 手续费：
 *   - LIMIT（挂限价） → maker 费率（通常 0.02%，0.0002）
 *   - MARKET / STOP_LOSS / TAKE_PROFIT（吃单或触发后立即下单） → taker（通常 0.04%，0.0004）
 *
 * 也支持按交易所、按 pair 单独配置（例如 BNB 抵扣会更便宜，后续扩展）
 */
class FeeCalculator
{
    /** @var float Maker 费率（小数，0.0002 = 0.02%）*/
    private $makerRate;

    /** @var float Taker 费率 */
    private $takerRate;

    public function __construct(float $makerRate = 0.0002, float $takerRate = 0.0004)
    {
        if ($makerRate < 0 || $takerRate < 0) {
            throw new InvalidArgumentException("Fee rate cannot be negative");
        }
        $this->makerRate = $makerRate;
        $this->takerRate = $takerRate;
    }

    /**
     * 计算手续费金额（stake 货币数量）
     *
     * @param string $orderType OrderType::*
     * @param float  $filledAmount filled base 数量
     * @param float  $fillPrice  平均成交价
     * @return float 手续费（stake 货币）
     */
    public function calculate(string $orderType, float $filledAmount, float $fillPrice): float
    {
        $cost = $filledAmount * $fillPrice;
        $rate = OrderType::isTaker($orderType) ? $this->takerRate : $this->makerRate;
        return $cost * $rate;
    }

    public function getMakerRate(): float { return $this->makerRate; }
    public function getTakerRate(): float { return $this->takerRate; }

    /**
     * 创建 Binance VIP0 默认手续费（maker 0.1%?，taker 0.1%）
     *
     * 说明：
     *   - Binance 现货标准是 maker 0.1% + taker 0.1%，大部分用户（VIP0）默认这样
     *   - 但期货 U本位 VIP0 是 maker 0.02%、taker 0.04%
     * 这里提供一个 helper 工厂，按模式返回合适计算器：
     */
    public static function binanceSpot(): self
    {
        return new self(0.001, 0.001);
    }

    public static function binanceFutures(): self
    {
        return new self(0.0002, 0.0004);
    }

    public static function okxSpot(): self
    {
        return new self(0.0008, 0.001);  // OKX 现货：maker 0.08% / taker 0.1%
    }

    public static function okxFutures(): self
    {
        return new self(0.0002, 0.0005);  // OKX 线性合约：maker 0.02% / taker 0.05%
    }
}
