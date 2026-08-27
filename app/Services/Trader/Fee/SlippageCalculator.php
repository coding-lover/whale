<?php

namespace App\Services\Trader\Fee;

use InvalidArgumentException;

/**
 * 滑点计算器（SlippageCalculator）
 *
 * 回测下订单不会真的"吃"进订单簿深度，所以我们要人为给一笔"吃单"加滑点：
 *   - 买 (BUY)：成交价 = 订单限价（或市价 close）× (1 + slippage)（亏一点买入）
 *   - 卖 (SELL)：成交价 = order_price × (1 - slippage)（卖少一点）
 *
 * 挂限价单（maker）一般没有滑点（因为你是 maker，挂在那里等成交），所以滑点只对吃单生效。
 *
 * 注意：这是一个简单模型，真实滑点还受订单大小 / 订单簿深度影响，
 * 如果未来要做更真实的"做市级"回测，可以替换本类的实现。
 */
class SlippageCalculator
{
    /** @var float 小数（0.001 = 0.1%），默认 0.1% */
    private $defaultSlippage;

    /** @var array<string, float> 按 pair 单独配置的滑点（优先级高于 default），如 BTC/USDT => 0.0005 */
    private $pairSlippage = [];

    public function __construct(float $defaultSlippage = 0.001, array $pairSlippage = [])
    {
        if ($defaultSlippage < 0) {
            throw new InvalidArgumentException("slippage cannot be negative");
        }
        $this->defaultSlippage = $defaultSlippage;
        foreach ($pairSlippage as $pair => $pct) {
            if ($pct < 0) {
                throw new InvalidArgumentException("{$pair} slippage cannot be negative: {$pct}");
            }
            $this->pairSlippage[(string) $pair] = (float) $pct;
        }
    }

    /**
     * 计算撮合时的实际成交价格
     *
     * @param string $symbolKey 标准格式 symbol string (BTC/USDT)
     * @param string $side      OrderSide::BUY / SELL
     * @param float  $basePrice 原价格（限价单的挂单价 / 市价单的 close）
     * @param bool   $isTaker   是否吃单（maker 一般 0 滑点）
     * @return float 实际撮合价（会浮动一点点）
     */
    public function applySlippage(string $symbolKey, string $side, float $basePrice, bool $isTaker): float
    {
        if ($basePrice <= 0) {
            throw new InvalidArgumentException("basePrice must be positive, got {$basePrice}");
        }
        if (!$isTaker) {
            return $basePrice;
        }
        $pct = $this->pairSlippage[$symbolKey] ?? $this->defaultSlippage;
        if ($pct == 0.0) {
            return $basePrice;
        }
        if ($side === \App\Services\Trader\Enum\OrderSide::BUY) {
            return $basePrice * (1 + $pct);
        }
        return $basePrice * (1 - $pct); // SELL
    }

    public function getDefaultSlippage(): float { return $this->defaultSlippage; }
}
