<?php

namespace App\Services\Exchanges\Formatters;

use App\Services\Exchanges\TradingSymbol;

/**
 * Binance 交易对格式化策略
 *
 * Binance 格式规则：
 * - 现货：BTCUSDT（无分隔符，base+quote 直接拼接）
 * - 永续合约（U本位）：BTCUSDT（与现货格式相同，通过不同端点区分）
 * - 永续合约（币本位）：BTCUSD_PERP（quote 为 USD 时加 _PERP 后缀）
 * - 交割合约（U本位）：BTCUSDT_250328（下划线 + YYMMDD）
 * - 交割合约（币本位）：BTCUSD_250328
 *
 * @see https://developers.binance.com/docs/binance-spot-api-docs
 */
class BinanceSymbolFormatter extends AbstractSymbolFormatter
{
    /**
     * 现货：BTCUSDT
     */
    protected function formatSpot(TradingSymbol $symbol): string
    {
        return $symbol->getBase() . $symbol->getQuote();
    }

    /**
     * 永续合约：U本位 BTCUSDT，币本位 BTCUSD_PERP
     */
    protected function formatSwap(TradingSymbol $symbol): string
    {
        $pair = $symbol->getBase() . $symbol->getQuote();

        // 币本位永续合约：quote 为 USD 时加 _PERP 后缀
        if ($symbol->getQuote() === 'USD') {
            return $pair . '_PERP';
        }

        // U本位永续合约：与现货格式相同，通过不同 API 端点区分
        return $pair;
    }

    /**
     * 交割合约：BTCUSDT_250328
     */
    protected function formatFutures(TradingSymbol $symbol, string $deliveryDate): string
    {
        return $symbol->getBase() . $symbol->getQuote() . '_' . $deliveryDate;
    }

    public function getExchangeName(): string
    {
        return 'binance';
    }
}
