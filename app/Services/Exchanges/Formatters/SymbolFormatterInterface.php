<?php

namespace App\Services\Exchanges\Formatters;

use App\Services\Exchanges\TradingSymbol;

/**
 * 交易对格式化策略接口
 *
 * 遵循策略模式，实现标准格式 与 交易所原生格式之间的双向转换：
 *   parseExchangeSymbol()   原生格式 → 标准 TradingSymbol
 *   format()                标准 TradingSymbol → 原生格式
 *
 * 每个交易所实现此接口，新增交易所只需新增实现类，无需修改现有代码。
 *
 * 设计原则：
 * - 单一职责：只负责格式转换
 * - 开闭原则：新增交易所不修改现有代码
 * - 依赖倒置：上层依赖抽象接口，不依赖具体实现
 */
interface SymbolFormatterInterface
{
    /**
     * 获取交易所名称
     *
     * @return string 如 binance、okx
     */
    public function getExchangeName(): string;

    /**
     * 将标准交易对转换为交易所原生格式
     *
     * @param TradingSymbol $symbol 标准交易对对象
     * @return string 交易所原生格式
     */
    public function format(TradingSymbol $symbol): string;

    /**
     * 将交易所原生格式解析为标准 TradingSymbol 对象（反向转换）
     *
     * 对于部分交易所（如 Binance）现货/永续合约没有格式上的明确区分，
     * 需要传入 $defaultType 指定默认类型（默认 TYPE_SPOT）。
     *
     * @param string $nativeSymbol 交易所原生格式（如 BTCUSDT、BTC-USDT-SWAP）
     * @param string $defaultType   无法从格式推断类型时使用的默认类型
     * @return TradingSymbol 标准交易对对象
     * @throws \InvalidArgumentException 当无法解析时抛出
     */
    public function parseExchangeSymbol(string $nativeSymbol, string $defaultType = TradingSymbol::TYPE_SPOT): TradingSymbol;
}
