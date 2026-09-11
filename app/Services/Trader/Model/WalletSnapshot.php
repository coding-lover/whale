<?php

namespace App\Services\Trader\Model;

/**
 * 钱包快照（单根 K 线结束时的总资产余额）
 *
 * 存到 list<WalletSnapshot> 后：
 *   - PerformanceReport 直接从 snapshots 数组算权益曲线、最大回撤
 *   - 前端 ECharts 画图也直接消费它的 timestamp / totalStake
 */
class WalletSnapshot
{
    /** @var int 毫秒时间戳 */
    private $timestampMs;

    /** @var float 折算成 stakeCurrency 的总资产（含挂单占用） */
    private $totalStake;

    /** @var string stake 货币名 */
    private $stakeCurrency;

    /**
     * 每币种明细，用于报表 CSV 导出
     * @var array<string, array{free:float,used:float}>
     */
    private $currencies;

    /**
     * @param array<string, array{free:float,used:float}> $currencies
     */
    public function __construct(
        int $timestampMs,
        float $totalStake,
        string $stakeCurrency,
        array $currencies
    ) {
        $this->timestampMs   = $timestampMs;
        $this->totalStake    = $totalStake;
        $this->stakeCurrency = $stakeCurrency;
        $this->currencies    = $currencies;
    }

    public function getTimestampMs(): int { return $this->timestampMs; }
    public function getTotalStake(): float { return $this->totalStake; }
    public function getStakeCurrency(): string { return $this->stakeCurrency; }

    /**
     * @return array<string, array{free:float,used:float}>
     */
    public function getCurrencies(): array { return $this->currencies; }

    /**
     * 转为数组（JSON / CSV）
     *
     * @return array{timestamp:int,total:float,stake_currency:string,iso:string,currencies:array}
     */
    public function toArray(): array
    {
        return [
            'timestamp'      => $this->timestampMs,
            'total'          => $this->totalStake,
            'stake_currency' => $this->stakeCurrency,
            'iso'            => gmdate('c', (int) ($this->timestampMs / 1000)),
            'currencies'     => $this->currencies,
        ];
    }
}
