<?php

namespace App\Services\Exchanges\Formatters;

use App\Services\Exchanges\TradingSymbol;

/**
 * 交易对格式化抽象基类
 *
 * 采用模板方法模式，定义双向转换流程骨架。
 *
 * 正向（标准 → 交易所原生）：
 *   format() 按产品类型分派给 formatSpot/formatSwap/formatFutures。
 *
 * 反向（交易所原生 → 标准）：
 *   parseExchangeSymbol() 子类根据各自交易所规则自行实现。
 *   基类提供通用辅助方法：
 *     - splitBaseQuoteFromConcatenated() 从无分隔符字符串中用白名单切分 base/quote
 *     - isValidDeliveryDate()           校验交割日期格式
 *
 * 新增交易所时继承此类并实现：
 *   - formatSpot() / formatSwap() / formatFutures()
 *   - parseExchangeSymbol()
 */
abstract class AbstractSymbolFormatter implements SymbolFormatterInterface
{
    /**
     * 常见计价货币白名单（无分隔符交易所切分 base/quote 时使用）
     *
     * 优先匹配长的 quote（USDT 优先于 USD）。
     * 覆盖 Binance/OKX 主流 quote，来源：Binance API /api/v3/exchangeInfo 的 quoteAsset 去重
     * 注：长度相同的 quote 在同一列表中彼此顺序无关；真正做切分时会按长度再次排序。
     */
    protected const COMMON_QUOTES = [
        // 6 字符
        'FDUSD', 'AEUR', 'BTCST', 'SUPER',
        // 5 字符
        'USDT', 'USDC', 'BUSD', 'TUSD', 'USDP', 'BIDR', 'BVND', 'DAI',
        'BTCB', 'EURT', 'PLN', 'RON', 'UAH', 'ZAR',
        // 4 字符
        'BRL', 'ARS', 'IDRT', 'NGN', 'RUB', 'TRY', 'VAI',
        'XVS', 'UMA', // 误匹配概率低的 3 字母 token 作 quote 时兜底
        // 3 字符法币 & 主流币
        'USD', 'EUR', 'JPY', 'GBP', 'CNY', 'AUD', 'CAD', 'CHF',
        'HKD', 'SGD', 'INR', 'KRW', 'MXN', 'THB', 'VND', 'PHP',
        'MYR', 'CZK', 'DKK', 'HUF', 'ILS', 'NOK', 'NZD', 'SEK',
        'BTC', 'ETH', 'BNB', 'XRP', 'SOL', 'ADA', 'DOT', 'LTC',
        'AVAX', 'LINK', 'ATOM', 'UNI', 'MATIC', 'ETC', 'FIL',
        'AAVE', 'MKR', 'NEO', 'EOS', 'XLM', 'TRX', 'DOGE', 'SHIB',
        'BCH', 'BSV', 'XTZ', 'QTUM', 'ZEC', 'OMG', 'SUSHI', 'YFI',
        'COMP', 'CRV', 'SNX', 'KNC', 'REN', 'LRC', 'BAND', 'OCEAN',
        // 2 字符 quote（放在最后避免误匹配）
        'HT', 'OKB', 'T', // T 是 Tether 在某些老市场的简写
    ];

    // ==================== 正向：标准 → 交易所原生 ====================

    /**
     * 模板方法：按产品类型分派给具体格式化方法
     */
    public function format(TradingSymbol $symbol): string
    {
        switch ($symbol->getType()) {
            case TradingSymbol::TYPE_SPOT:
                return $this->formatSpot($symbol);

            case TradingSymbol::TYPE_SWAP:
                return $this->formatSwap($symbol);

            case TradingSymbol::TYPE_FUTURES:
                $date = $symbol->getResolvedDeliveryDate();
                if ($date === null) {
                    throw new \RuntimeException(
                        "Futures symbol requires delivery date or period: {$symbol}"
                    );
                }
                return $this->formatFutures($symbol, $date);

            default:
                throw new \RuntimeException(
                    "Unknown instrument type: " . $symbol->getType()
                );
        }
    }

    abstract protected function formatSpot(TradingSymbol $symbol): string;
    abstract protected function formatSwap(TradingSymbol $symbol): string;
    abstract protected function formatFutures(TradingSymbol $symbol, string $deliveryDate): string;

    // ==================== 反向：交易所原生 → 标准 ====================

    /**
     * 将交易所原生格式解析为标准 TradingSymbol（由子类实现具体规则）
     */
    abstract public function parseExchangeSymbol(string $nativeSymbol, string $defaultType = TradingSymbol::TYPE_SPOT): TradingSymbol;

    // ==================== 反向解析辅助方法 ====================

    /**
     * 从无分隔符拼接的交易对中切分 base/quote
     *
     * 适用于 Binance 这类「BTCUSDT」没有分隔符的交易所。
     * 用白名单 quote 从右向左匹配，优先长 quote（USDT 优先于 USD）。
     *
     * 示例：
     *   'BTCUSDT'   → ['BTC',   'USDT']
     *   'BTCUSD'    → ['BTC',   'USD']
     *   'ETHUSDC'   → ['ETH',   'USDC']
     *   'BTCUSDT1'  → 找不到匹配，返回 null
     *
     * @param string $pair 无分隔符拼接的交易对（不含日期后缀）
     * @return array|null [base, quote] 或 null（无法识别时）
     */
    protected function splitBaseQuoteFromConcatenated(string $pair): ?array
    {
        $pairUpper = strtoupper($pair);

        // 按长度倒序尝试匹配 quote（避免 USDT 被拆成 USD）
        $quotesByLength = $this->getQuotesByLengthDesc();
        foreach ($quotesByLength as $quote) {
            if (strlen($quote) >= strlen($pairUpper)) {
                continue;
            }
            if (substr($pairUpper, -strlen($quote)) === $quote) {
                $base = substr($pairUpper, 0, -strlen($quote));
                if ($base !== '') {
                    return [$base, $quote];
                }
            }
        }

        return null;
    }

    /**
     * 检查字符串是否是合法的交割日期后缀（6 位或 8 位数字）
     */
    protected function isValidDeliveryDate(string $date): bool
    {
        return (bool) preg_match('/^\d{6,8}$/', $date);
    }

    // ==================== 内部工具 ====================

    /**
     * 按 quote 长度从大到小排序（用于无分隔符切分时优先匹配）
     *
     * @return string[]
     */
    private function getQuotesByLengthDesc(): array
    {
        $quotes = static::COMMON_QUOTES;
        usort($quotes, static function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        return $quotes;
    }
}
