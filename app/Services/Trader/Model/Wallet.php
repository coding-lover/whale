<?php

namespace App\Services\Trader\Model;

use InvalidArgumentException;

/**
 * 钱包：多币种余额账本（等价 Freqtrade Wallets）
 *
 * 内存实现，结构：currencies[USDT] = free+used
 *
 * 设计考虑：
 *   - 回测下每次下单调用 canAfford() / debit() / credit() 三步，保证不会超余额
 *   - used = 挂单占用（回测模式下 limit 挂单未成交时占 used）
 *   - 每根 K 线结束后会拍一个 WalletSnapshot 存入权益曲线（PerformanceReport 用）
 */
class Wallet
{
    /**
     * 每币种余额结构 free/used
     * @var array<string, array{free:float, used:float}>
     */
    private $currencies = [];

    /** @var string stake 货币（默认 USDT，算 USD 盈亏基准用）*/
    private $stakeCurrency;

    public function __construct(string $stakeCurrency = 'USDT')
    {
        $this->stakeCurrency = $stakeCurrency;
    }

    /**
     * 初始化某币种的起始余额（回测开始时调一次）
     */
    public function setBalance(string $currency, float $free, float $used = 0.0): void
    {
        if ($free < 0 || $used < 0) {
            throw new InvalidArgumentException("Balance cannot be negative: {$currency} free={$free} used={$used}");
        }
        $this->currencies[$currency] = ['free' => $free, 'used' => $used];
    }

    /**
     * 检查是否有足够 free 余额用于开仓（含 1e-9 浮点容差）
     */
    public function canAfford(string $currency, float $amount): bool
    {
        $bal = $this->currencies[$currency] ?? ['free' => 0.0, 'used' => 0.0];
        return $bal['free'] + 1e-9 >= $amount;
    }

    /**
     * 扣减余额（开仓、买入时使用）：free 减
     *
     * @param float $amount
     */
    public function debit(string $currency, float $amount): void
    {
        if ($amount < -1e-9) {
            throw new InvalidArgumentException("debit amount negative: {$amount}");
        }
        if (!$this->canAfford($currency, $amount)) {
            $bal = $this->currencies[$currency]['free'] ?? 0.0;
            throw new InvalidArgumentException(
                "Insufficient {$currency}: need {$amount}, free only {$bal}"
            );
        }
        if (!isset($this->currencies[$currency])) {
            $this->currencies[$currency] = ['free' => 0.0, 'used' => 0.0];
        }
        $this->currencies[$currency]['free'] = max(0.0, $this->currencies[$currency]['free'] - $amount);
    }

    /**
     * 加余额（平仓卖出、收到转账、充值、赚到手续费返还）
     */
    public function credit(string $currency, float $amount): void
    {
        if ($amount < -1e-9) {
            throw new InvalidArgumentException("credit amount negative: {$amount}");
        }
        if (!isset($this->currencies[$currency])) {
            $this->currencies[$currency] = ['free' => 0.0, 'used' => 0.0];
        }
        $this->currencies[$currency]['free'] += $amount;
    }

    /**
     * 占用/释放挂单：free → used（挂限价单未成交）或 used → free（撤单后）
     */
    public function moveToUsed(string $currency, float $amount): void
    {
        $this->debit($currency, $amount);
        $this->currencies[$currency]['used'] += $amount;
    }

    public function releaseFromUsed(string $currency, float $amount): void
    {
        if (!isset($this->currencies[$currency]) || $this->currencies[$currency]['used'] + 1e-9 < $amount) {
            throw new InvalidArgumentException("Cannot release {$amount} {$currency} from used");
        }
        $this->currencies[$currency]['used'] -= $amount;
        $this->currencies[$currency]['free'] += $amount;
    }

    /**
     * 获取某币种 free 余额
     */
    public function getFree(string $currency): float
    {
        return isset($this->currencies[$currency]) ? $this->currencies[$currency]['free'] : 0.0;
    }

    /**
     * 获取某币种 total（free+used）
     */
    public function getTotal(string $currency): float
    {
        if (!isset($this->currencies[$currency])) {
            return 0.0;
        }
        return $this->currencies[$currency]['free'] + $this->currencies[$currency]['used'];
    }

    /**
     * 生成快照（写入权益曲线）
     *
     * @param int   $timestampMs 时间戳
     * @param array<string, float> $quotePrices 其他币种相对 stakeCurrency 的收盘价（用于折算总资产）
     *                                         key 是 "BTC" 这种基准币，value 是 USDT 收盘价
     */
    public function snapshot(int $timestampMs, array $quotePrices = []): WalletSnapshot
    {
        $totalStake = $this->getFree($this->stakeCurrency) + $this->currencies[$this->stakeCurrency]['used'];
        foreach ($this->currencies as $cur => $bal) {
            if ($cur === $this->stakeCurrency) {
                continue;
            }
            $rate = $quotePrices[$cur] ?? 0.0;
            $totalStake += ($bal['free'] + $bal['used']) * $rate;
        }
        // 拷贝一份 currency 明细
        $snapCurrencies = [];
        foreach ($this->currencies as $c => $b) {
            $snapCurrencies[$c] = ['free' => $b['free'], 'used' => $b['used']];
        }
        return new WalletSnapshot($timestampMs, $totalStake, $this->stakeCurrency, $snapCurrencies);
    }

    public function getStakeCurrency(): string { return $this->stakeCurrency; }

    /**
     * 返回当前所有币种及余额（debug 用）
     * @return array<string, array{free:float,used:float}>
     */
    public function getAllCurrencies(): array
    {
        return $this->currencies;
    }
}
