<?php

namespace App\Services\Exchanges\Formatters;

use App\Services\Exchanges\TradingSymbol;

/**
 * OKX 交易对格式化策略
 *
 * OKX 格式规则（全用短横线分隔）：
 *   现货：       BTC-USDT             两段，第二段不是 SWAP 也不是日期
 *   永续合约：    BTC-USDT-SWAP        三段，末尾 SWAP
 *   交割合约：    BTC-USDT-250328      三段，末尾 6/8 位数字日期
 *
 * 反向解析比 Binance 简单，因为有明确分隔符和后缀。
 *
 * @see https://www.okx.com/docs-v5/en/
 */
class OkxSymbolFormatter extends AbstractSymbolFormatter
{
    // ==================== 正向 ====================

    protected function formatSpot(TradingSymbol $symbol): string
    {
        return $symbol->getBase() . '-' . $symbol->getQuote();
    }

    protected function formatSwap(TradingSymbol $symbol): string
    {
        return $symbol->getBase() . '-' . $symbol->getQuote() . '-SWAP';
    }

    protected function formatFutures(TradingSymbol $symbol, string $deliveryDate): string
    {
        return $symbol->getBase() . '-' . $symbol->getQuote() . '-' . $deliveryDate;
    }

    public function getExchangeName(): string
    {
        return 'okx';
    }

    // ==================== 反向 ====================

    public function parseExchangeSymbol(string $nativeSymbol, string $defaultType = TradingSymbol::TYPE_SPOT): TradingSymbol
    {
        $nativeSymbol = trim(strtoupper($nativeSymbol));
        if ($nativeSymbol === '') {
            throw new \InvalidArgumentException("OKX symbol cannot be empty");
        }

        $parts = explode('-', $nativeSymbol);

        switch (count($parts)) {
            case 2:
                // 两段：现货 base-quote
                [$base, $quote] = $parts;
                if ($base === '' || $quote === '') {
                    throw new \InvalidArgumentException("Invalid OKX spot symbol: {$nativeSymbol}");
                }
                return new TradingSymbol($base, $quote, TradingSymbol::TYPE_SPOT);

            case 3:
                // 三段：base-quote-XXX，XXX 可能是 SWAP 或日期
                [$base, $quote, $suffix] = $parts;
                if ($base === '' || $quote === '' || $suffix === '') {
                    throw new \InvalidArgumentException("Invalid OKX 3-segment symbol: {$nativeSymbol}");
                }

                if ($suffix === 'SWAP') {
                    return new TradingSymbol($base, $quote, TradingSymbol::TYPE_SWAP);
                }

                if ($this->isValidDeliveryDate($suffix)) {
                    $symbol = new TradingSymbol($base, $quote, TradingSymbol::TYPE_FUTURES, $suffix);
                    // 本地标准格式优先用周期别名（QUARTER 等），匹配则归一化
                    $symbol->normalizeToPeriodAlias();
                    return $symbol;
                }

                throw new \InvalidArgumentException(
                    "Invalid OKX 3-segment symbol '{$nativeSymbol}': suffix should be 'SWAP' or a valid delivery date"
                );

            default:
                throw new \InvalidArgumentException(
                    "Invalid OKX symbol format: {$nativeSymbol}, expected 2 or 3 segments separated by '-'"
                );
        }
    }
}
