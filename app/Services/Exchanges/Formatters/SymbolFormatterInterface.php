<?php

namespace App\Services\Exchanges\Formatters;

use App\Services\Exchanges\TradingSymbol;

/**
 * 交易对格式化策略接口
 *
 * 遵循策略模式，将标准 TradingSymbol 转换为各交易所原生格式。
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
}
