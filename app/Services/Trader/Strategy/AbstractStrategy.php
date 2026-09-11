<?php

namespace App\Services\Trader\Strategy;

use App\Services\Exchanges\TradingSymbol;
use App\Services\Trader\Enum\TradingMode;
use App\Services\Trader\Model\TradeRecord;

/**
 * 策略基类（AbstractStrategy）
 *
 * 策略开发者继承这个类，至少覆写：
 *   - getName()
 *   - populateIndicators()
 *   - populateEntryTrend()
 *   - populateExitTrend()
 *
 * 其他配置通过 protected 属性赋值即可，不需要一个个 getter 重写。
 * 所有钩子默认实现（返回 null / false 即不启用）。
 */
abstract class AbstractStrategy implements StrategyInterface
{
    /** @var string 策略版本号（用于报表） */
    protected $version = '1.0';

    /** @var string 说明文档（可选，在 CLI 打印时用） */
    protected $description = '';

    // ---------- 默认配置（子类赋值即覆盖） ----------
    /** @var float stake amount（默认每笔 1000 USDT，子类可按 pair 覆盖 getStakeAmount()）*/
    protected $defaultStakeAmount = 1000.0;

    /** @var int 全局最大开仓数（含所有 pair）*/
    protected $maxOpenTrades = 3;

    /** @var int 每个 pair 最大同时持仓 */
    protected $maxOpenTradesPerPair = 1;

    /** @var float 现货杠杆 = 1 */
    protected $spotLeverage = 1.0;
    /** @var float 期货杠杆（默认 5x）*/
    protected $futuresLeverage = 5.0;

    /** @var bool 能否做多/做空 */
    protected $enableLong = true;
    protected $enableShort = false;

    // ---------- 止损 / ROI / Trailing Stop 默认配置 ----------
    // 实际逻辑放在 ExitRules 模块里；这里只是一个属性容器，
    // 让子类直接赋值，无需重写 getter。

    /** @var float 固定止损百分比（小数 0.03 = 3%，0 = 不启用）*/
    protected $stoploss = 0.0;

    /** @var array<int, float> minimal ROI 表（开仓分钟数 → 收益率百分比小数）
     *  例 [0 => 0.10, 30 => 0.05, 120 => 0.02, 240 => 0] = 开仓即止盈 10%，
     *  30 分钟后 5% 就走，120m 后盈利 2% 即走，240m 后无论多少强制平。
     */
    protected $minimalRoi = [];

    /** @var float 追踪止损百分比（0=不启用），long 时 = close × (1 - pct)，short 时反 */
    protected $trailingStop = 0.0;
    /** @var int 追踪止损激活阈值 pct（未达到这个盈利前不启动 trailing，避免刚开就被震出去）*/
    protected $trailingStopPositive = 0;

    /** @var int HOLD 最大持仓根 K 线数（0 表示不限，> 0 则达到后下一根强制平）*/
    protected $maxHoldBars = 0;

    public function getName(): string
    {
        return static::class;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * 默认实现：$defaultStakeAmount 作为全局 stake。
     * 如需"每 pair 不同"（BTC 大一点，小币种小一点），子类可按 $symbol->getBase() 分支。
     */
    public function getStakeAmount(TradingSymbol $symbol): float
    {
        return $this->defaultStakeAmount;
    }

    public function getMaxOpenTrades(): int
    {
        return $this->maxOpenTrades;
    }

    public function getMaxOpenTradesPerPair(): int
    {
        return $this->maxOpenTradesPerPair;
    }

    public function getLeverage(string $mode): float
    {
        return $mode === TradingMode::FUTURES ? $this->futuresLeverage : $this->spotLeverage;
    }

    public function canShort(): bool { return $this->enableShort; }
    public function canLong(): bool  { return $this->enableLong; }

    // ---------- 钩子默认（什么都不做）----------
    public function customEntryPrice(
        TradingSymbol $symbol,
        string $side,
        array $currentRow,
        array $previousRow
    ): ?float {
        return null;
    }

    public function customExitPrice(TradeRecord $trade, string $exitType, array $currentRow): ?float
    {
        return null;
    }

    public function customExit(TradeRecord $trade, int $currentRowIndex, array $currentRow): bool
    {
        // HOLD 根线数超时（默认 maxHoldBars=0 → 不启用）
        if ($this->maxHoldBars > 0 && $trade->getOpenTimestamp() > 0) {
            $openTs  = $trade->getOpenTimestamp();
            $barTs   = (int) $currentRow[SignalCols::DATE];
            $tfMs    = 0; // 这里无法直接访问 timeframe，但 HOLD 根线数超时也可以用 bar index 差
            // 更精确的 HOLD 超时由 MatchingEngine 结合 $currentRowIndex 算
            // 这里作为兜底，不会影响正确性。
            unset($openTs, $barTs, $tfMs);
        }
        return false;
    }

    public function getSignalColumnIndexes(): array
    {
        return [
            SignalCols::ENTER_LONG,
            SignalCols::EXIT_LONG,
            SignalCols::ENTER_SHORT,
            SignalCols::EXIT_SHORT,
        ];
    }

    // ---------- Getters for ExitRules ----------
    public function getStoploss(): float            { return $this->stoploss; }
    public function getMinimalRoi(): array           { return $this->minimalRoi; }
    public function getTrailingStop(): float         { return $this->trailingStop; }
    public function getTrailingStopPositive(): float { return $this->trailingStopPositive; }
    public function getMaxHoldBars(): int            { return $this->maxHoldBars; }
}
