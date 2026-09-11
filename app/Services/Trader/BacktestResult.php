<?php

namespace App\Services\Trader;

/**
 * Backtesting.run() 返回对象（纯值对象，用于报表/导出）
 */
class BacktestResult
{
    /** @var string 策略类名或自定义名 */
    private $strategyName;
    /** @var string 策略版本 */
    private $strategyVersion;
    /** @var string RunMode */
    private $runMode;
    /** @var string TradingMode */
    private $tradingMode;
    /** @var string stake 货币 */
    private $stakeCurrency;
    /** @var string 回测周期 */
    private $timeframe;

    /**
     * 所有交易（扁平数组，来自 TradeRecord::toArray()）
     * @var array<int, array<string, mixed>>
     */
    private $trades;

    /**
     * 每根 K 线钱包快照（权益曲线）
     * @var array<int, array{timestamp:int,total:float,stake_currency:string,iso:string,currencies:array}>
     */
    private $equityCurve;

    /** @var int 信号总数（含被拒） */
    private $signalsTotal;
    /** @var int 被 ProtectionManager 拒的信号数 */
    private $rejectedSignals;
    /** @var float 回测结束时 stake currency 总余额 */
    private $finalBalance;

    /**
     * @param array<int, array<string, mixed>>                                      $trades
     * @param array<int, array{timestamp:int,total:float,stake_currency:string,iso:string,currencies:array}> $equityCurve
     */
    public function __construct(
        string $strategyName,
        string $strategyVersion,
        string $runMode,
        string $tradingMode,
        string $stakeCurrency,
        string $timeframe,
        array $trades,
        array $equityCurve,
        int $signalsTotal,
        int $rejectedSignals,
        float $finalBalance
    ) {
        $this->strategyName    = $strategyName;
        $this->strategyVersion = $strategyVersion;
        $this->runMode         = $runMode;
        $this->tradingMode     = $tradingMode;
        $this->stakeCurrency   = $stakeCurrency;
        $this->timeframe       = $timeframe;
        $this->trades          = $trades;
        $this->equityCurve     = $equityCurve;
        $this->signalsTotal    = $signalsTotal;
        $this->rejectedSignals = $rejectedSignals;
        $this->finalBalance    = $finalBalance;
    }

    // ------ Getters ------
    public function getStrategyName(): string    { return $this->strategyName; }
    public function getStrategyVersion(): string { return $this->strategyVersion; }
    public function getRunMode(): string         { return $this->runMode; }
    public function getTradingMode(): string     { return $this->tradingMode; }
    public function getStakeCurrency(): string   { return $this->stakeCurrency; }
    public function getTimeframe(): string       { return $this->timeframe; }

    /** @return array<int, array<string, mixed>> */
    public function getTrades(): array           { return $this->trades; }

    /** @return array<int, array{timestamp:int,total:float,stake_currency:string,iso:string,currencies:array}> */
    public function getEquityCurve(): array      { return $this->equityCurve; }

    public function getSignalsTotal(): int       { return $this->signalsTotal; }
    public function getRejectedSignals(): int    { return $this->rejectedSignals; }
    public function getFinalBalance(): float     { return $this->finalBalance; }
    public function getTradeCount(): int         { return count($this->trades); }
    public function getVersion(): string         { return $this->strategyVersion; }
}
