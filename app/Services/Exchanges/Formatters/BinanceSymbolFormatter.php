<?php

namespace App\Services\Exchanges\Formatters;

use App\Services\Exchanges\TradingSymbol;

/**
 * Binance 交易对格式化策略
 *
 * Binance 格式规则：
 *   现货：          BTCUSDT            （无分隔符，base+quote 直接拼接）
 *   U本位永续：     BTCUSDT            （与现货格式完全相同，靠端点区分）
 *   币本位永续：    BTCUSD_PERP        （quote = USD 时加 _PERP 后缀）
 *   U本位交割：     BTCUSDT_250328     （下划线 + 6/8 位日期）
 *   币本位交割：    BTCUSD_250328
 *
 * 反向解析注意：
 *   - BTCUSDT 无法从格式区分是现货还是 U本位永续，通过 $defaultType 指定（默认现货）
 *   - _PERP 后缀和 _日期后缀能明确识别币本位永续和交割合约
 *
 * @see https://developers.binance.com/docs/binance-spot-api-docs
 */
class BinanceSymbolFormatter extends AbstractSymbolFormatter
{
    // ==================== 正向 ====================

    protected function formatSpot(TradingSymbol $symbol): string
    {
        return $symbol->getBase() . $symbol->getQuote();
    }

    protected function formatSwap(TradingSymbol $symbol): string
    {
        $pair = $symbol->getBase() . $symbol->getQuote();
        // 币本位永续合约：quote 为 USD 时加 _PERP 后缀
        if ($symbol->getQuote() === 'USD') {
            return $pair . '_PERP';
        }
        return $pair;
    }

    protected function formatFutures(TradingSymbol $symbol, string $deliveryDate): string
    {
        return $symbol->getBase() . $symbol->getQuote() . '_' . $deliveryDate;
    }

    public function getExchangeName(): string
    {
        return 'binance';
    }

    // ==================== 反向 ====================

    public function parseExchangeSymbol(string $nativeSymbol, string $defaultType = TradingSymbol::TYPE_SPOT): TradingSymbol
    {
        $nativeSymbol = trim(strtoupper($nativeSymbol));
        if ($nativeSymbol === '') {
            throw new \InvalidArgumentException("Binance symbol cannot be empty");
        }

        // 规则 1：币本位永续合约 —— 后缀 _PERP
        //   BTCUSD_PERP → swap, base=BTC, quote=USD
        if (substr($nativeSymbol, -5) === '_PERP') {
            $pairPart = substr($nativeSymbol, 0, -5);
            $split = $this->splitBaseQuoteFromConcatenated($pairPart);
            if ($split === null) {
                throw new \InvalidArgumentException(
                    "Cannot parse Binance coin-margined swap symbol: {$nativeSymbol}"
                );
            }
            return new TradingSymbol($split[0], $split[1], TradingSymbol::TYPE_SWAP);
        }

        // 规则 2：交割合约 —— 末尾 _YYMMDD 或 _YYYYMMDD
        //   BTCUSDT_250328 → futures
        if (preg_match('/_(\d{6,8})$/', $nativeSymbol, $m)) {
            $date = $m[1];
            $pairPart = substr($nativeSymbol, 0, -strlen($m[0]));
            $split = $this->splitBaseQuoteFromConcatenated($pairPart);
            if ($split === null) {
                throw new \InvalidArgumentException(
                    "Cannot parse Binance futures symbol: {$nativeSymbol}"
                );
            }
            $symbol = new TradingSymbol($split[0], $split[1], TradingSymbol::TYPE_FUTURES, $date);
            // 本地标准格式优先用周期别名（QUARTER 等），匹配则归一化
            $symbol->normalizeToPeriodAlias();
            return $symbol;
        }

        // 规则 3：现货 或 U本位永续合约（格式相同，靠 $defaultType 决定）
        //   BTCUSDT → 默认 spot；若 defaultType=swap 则返回 swap
        $split = $this->splitBaseQuoteFromConcatenated($nativeSymbol);
        if ($split === null) {
            throw new \InvalidArgumentException(
                "Cannot parse Binance symbol: {$nativeSymbol} (unknown quote currency)"
            );
        }
        if (!in_array($defaultType, [TradingSymbol::TYPE_SPOT, TradingSymbol::TYPE_SWAP], true)) {
            throw new \InvalidArgumentException(
                "Invalid default type for Binance plain symbol: {$defaultType}"
            );
        }
        return new TradingSymbol($split[0], $split[1], $defaultType);
    }
}
