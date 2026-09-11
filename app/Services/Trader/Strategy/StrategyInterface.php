<?php

namespace App\Services\Trader\Strategy;

use App\Services\Exchanges\TradingSymbol;
use App\Services\Trader\Enum\TradingMode;
use App\Services\Trader\Model\TradeRecord;

/**
 * 策略接口（Strategy Interface）
 *
 * 对应 Freqtrade 的 IStrategy。策略编写者需要实现三个"信号列填充"方法，以及若干自定义钩子。
 * 所有方法都必须是"纯函数式"：只读入参（不修改），返回新数组或新对象。
 * 这样回测 / 实盘 / 多进程（hyperopt）可以复用同一个 Strategy 实例，无副作用。
 */
interface StrategyInterface
{
    /**
     * 返回策略可读名称（报表中展示）
     */
    public function getName(): string;

    /**
     * 第二步：计算指标
     *
     * 传入 OHLCV 矩阵（SignalCols::candlesToMatrix 生成的 12 列标准矩阵 + 自定义列），
     * 可附加 MACD、RSI、BB 等列，最后返回"扩展后的矩阵"。
     *
     * 约定：
     *   - 不要修改 $matrix 引用，返回新的数组（便于未来多进程优化、无副作用调试）
     *   - 新列加在 SignalCols::NUM_COLUMNS 之后，使用任何整型下标即可
     *
     * @param array<int, array<int, mixed>> $matrix  12 列基础矩阵
     * @param TradingSymbol                  $symbol  交易对
     * @param string                         $timeframe  周期
     * @return array<int, array<int, mixed>> 扩展后的矩阵
     */
    public function populateIndicators(array $matrix, TradingSymbol $symbol, string $timeframe): array;

    /**
     * 第三步：写入入场信号列（ENTER_LONG / ENTER_SHORT / ENTER_TAG）
     *
     * @param array<int, array<int, mixed>> $matrix  populateIndicators 后的矩阵
     * @return array<int, array<int, mixed>> 写好 enter_long/enter_short/enter_tag 三列
     */
    public function populateEntryTrend(array $matrix): array;

    /**
     * 第四步：写出场信号列（EXIT_LONG / EXIT_SHORT / EXIT_TAG）
     */
    public function populateExitTrend(array $matrix): array;

    /**
     * 单笔 stake 金额（USDT 数），策略可以按 pair 动态配置
     */
    public function getStakeAmount(TradingSymbol $symbol): float;

    /**
     * 最大允许未平仓交易数（全局总上限）
     */
    public function getMaxOpenTrades(): int;

    /**
     * 每 pair 同时持有的最大交易数（防止同一个 BTC/USDT 连开 10 个单）
     */
    public function getMaxOpenTradesPerPair(): int;

    /**
     * 杠杆倍数（仅期货/杠杆适用，现货返回 1）
     * @param string $mode TradingMode::* 类常量字符串
     */
    public function getLeverage(string $mode): float;

    /**
     * 是否允许做空（现货一般 false）
     */
    public function canShort(): bool;

    /**
     * 是否允许长仓（几乎永远 true，有些套利策略只做空可以设 false）
     */
    public function canLong(): bool;

    // ---- 钩子：策略可以在真实下单/平仓前介入 ----

    /**
     * 自定义入场价格（返回 null 就用默认下一根 open，带滑点）
     */
    public function customEntryPrice(
        TradingSymbol $symbol,
        string $side,
        array $currentRow,
        array $previousRow
    ): ?float;

    /**
     * 自定义平仓价格
     */
    public function customExitPrice(
        TradeRecord $trade,
        string $exitType,
        array $currentRow
    ): ?float;

    /**
     * 自定义平仓：在每根 K 线 exit_signal 之前调用
     *  - 返回 true：立即平仓（退出原因 = CUSTOM_EXIT）
     *  - 返回 false：继续执行其他退出规则
     *
     * 作用：实现"保本退出"、"持仓 N 根后强制平"等高级策略
     */
    public function customExit(TradeRecord $trade, int $currentRowIndex, array $currentRow): bool;

    /**
     * 返回此策略的 enter/exit 列索引（方便统一访问，默认用 SignalCols 常量即可）
     * 返回 [enter_long, exit_long, enter_short, exit_short]
     * @return array{int,int,int,int}
     */
    public function getSignalColumnIndexes(): array;

    // ---- 止损 / ROI / Trailing Stop / HOLD 配置 ----

    /** 固定止损百分比（小数 0.03=3%，0 = 不启用） */
    public function getStoploss(): float;

    /** minimal ROI 阶梯表（开仓分钟整数 => 目标收益率小数），空数组不启用 */
    public function getMinimalRoi(): array;

    /** 追踪止损百分比小数（0=不启用）*/
    public function getTrailingStop(): float;

    /** 追踪止损激活阈值小数（达到多少未实现盈后才启动 trailing，0=立即启用）*/
    public function getTrailingStopPositive(): float;

    /** 最大持仓 K 线数（0=不限）*/
    public function getMaxHoldBars(): int;
}
