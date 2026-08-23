<?php

namespace App\Services\Exchanges\Formatters;

use App\Services\Exchanges\TradingSymbol;

/**
 * 交易对格式化抽象基类
 *
 * 采用模板方法模式，定义转换流程骨架：
 *   1. 检查交割合约的交割日期是否已解析
 *   2. 按产品类型分派给子类的 spot/swap/futures 格式化方法
 *
 * 子类只需实现三个抽象方法：
 *   - formatSpot()    现货格式转换
 *   - formatSwap()    永续合约格式转换
 *   - formatFutures() 交割合约格式转换
 *
 * 新增交易所时继承此类并实现三个方法即可
 */
abstract class AbstractSymbolFormatter implements SymbolFormatterInterface
{
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
                // 交割合约需要确保交割日期已解析
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

    /**
     * 现货格式转换
     *
     * @param TradingSymbol $symbol 标准交易对（base/quote 已大写）
     * @return string 交易所原生现货格式
     */
    abstract protected function formatSpot(TradingSymbol $symbol): string;

    /**
     * 永续合约格式转换
     *
     * @param TradingSymbol $symbol 标准交易对
     * @return string 交易所原生永续合约格式
     */
    abstract protected function formatSwap(TradingSymbol $symbol): string;

    /**
     * 交割合约格式转换
     *
     * @param TradingSymbol $symbol 标准交易对
     * @param string $deliveryDate 已解析的交割日期 YYMMDD
     * @return string 交易所原生交割合约格式
     */
    abstract protected function formatFutures(TradingSymbol $symbol, string $deliveryDate): string;
}
