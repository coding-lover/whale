<?php

namespace App\Services\Exchanges\Formatters;

use App\Services\Exchanges\TradingSymbol;

/**
 * OKX 交易对格式化策略
 *
 * OKX 格式规则：
 * - 现货：BTC-USDT（短横线分隔）
 * - 永续合约：BTC-USDT-SWAP（-SWAP 后缀）
 * - 交割合约：BTC-USDT-250328（短横线 + YYMMDD）
 *
 * OKX 所有产品类型均用短横线分隔，通过后缀区分类型
 *
 * @see https://www.okx.com/docs-v5/en/
 */
class OkxSymbolFormatter extends AbstractSymbolFormatter
{
    /**
     * 现货：BTC-USDT
     */
    protected function formatSpot(TradingSymbol $symbol): string
    {
        return $symbol->getBase() . '-' . $symbol->getQuote();
    }

    /**
     * 永续合约：BTC-USDT-SWAP
     */
    protected function formatSwap(TradingSymbol $symbol): string
    {
        return $symbol->getBase() . '-' . $symbol->getQuote() . '-SWAP';
    }

    /**
     * 交割合约：BTC-USDT-250328
     */
    protected function formatFutures(TradingSymbol $symbol, string $deliveryDate): string
    {
        return $symbol->getBase() . '-' . $symbol->getQuote() . '-' . $deliveryDate;
    }

    public function getExchangeName(): string
    {
        return 'okx';
    }
}
