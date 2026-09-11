<?php

namespace App\Services\Trader\Market;

use App\Services\Exchanges\TradingSymbol;

/**
 * 行情数据提供者接口（Data Provider 抽象）
 *
 * 这个接口是"回测/实盘同一套代码"的核心：
 *   - 回测模式：ArrayDataProvider（从 CSV/数组读取历史 K 线）
 *   - Dry-run / 实盘：ExchangeDataProvider（通过 ExchangeManager 拉真实 K 线、缓存到内存）
 *
 * 不管是哪一个实现，上层（Strategy / MatchingEngine）调用的都是同一个接口，
 * 从而保证策略代码在两个模式下行为一致。
 */
interface DataProviderInterface
{
    /**
     * 获取某交易对 + 周期的 K 线序列
     *
     * 调用约定：
     *  - 按时间戳升序排列；不得有乱序或重复
     *  - 如果 $fromMs/$toMs 为 null，代表尽可能返回全部已加载范围
     *  - 返回的 K 线必须能用 TradingSymbol 表示（即本系统标准格式）
     *
     * @param TradingSymbol $symbol   本系统标准交易对
     * @param string        $timeframe Timeframe::TF_* 常量
     * @param int|null      $fromMs    起始毫秒（含）
     * @param int|null      $toMs      结束毫秒（含）
     * @return Candle[] 索引为 0..N-1 的数组（撮合引擎按下标顺序推进）
     */
    public function getCandles(
        TradingSymbol $symbol,
        string $timeframe,
        ?int $fromMs = null,
        ?int $toMs = null
    ): array;

    /**
     * 获取某个 pair 加载后的首根/末根 K 线时间戳范围（不满足时用 throw 报错）
     *
     * @return array{0:int,1:int} [firstTimestampMs, lastTimestampMs]
     */
    public function getAvailableRange(TradingSymbol $symbol, string $timeframe): array;

    /**
     * 返回当前 DataProvider 覆盖的所有交易对列表
     *
     * @return TradingSymbol[]
     */
    public function getAvailableSymbols(): array;

    /**
     * 返回当前 DataProvider 中已加载数据的所有周期（去重）
     *
     * 用于 Backtesting::run() 在未显式指定 timeframe 时自动推导：
     *   - 返回 0 个：provider 为空（没有任何数据）
     *   - 返回 1 个：可安全地自动使用该周期
     *   - 返回多个：存在歧义，调用方必须显式指定要回测的周期
     *
     * @return string[] Timeframe::TF_* 字符串列表（如 ['1h']）
     */
    public function getAvailableTimeframes(): array;

    /**
     * 检查指定 pair+timeframe 是否有足够的数据（最少数目）
     *
     * @param int $minCandles 最少需要几根 K 线（比如指标预热需要 200 根）
     */
    public function hasEnoughData(TradingSymbol $symbol, string $timeframe, int $minCandles): bool;
}
