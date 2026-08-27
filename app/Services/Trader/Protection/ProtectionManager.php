<?php

namespace App\Services\Trader\Protection;

use App\Services\Exchanges\TradingSymbol;
use App\Services\Trader\Model\TradeRecord;
use App\Services\Trader\Strategy\StrategyInterface;
use InvalidArgumentException;

/**
 * 保护管理器（ProtectionManager）
 *
 * 负责所有"策略允许下单之前"的全局检查：
 *   - 开仓总数量是否超过 strategy.maxOpenTrades
 *   - 每 pair 开仓数量是否超过 strategy.maxOpenTradesPerPair
 *   - pair 冷却期锁（PairLock）
 *   - 连续亏损停止（StopLossGuard，可选）
 *   - （预留）全局最大回撤熔断、单日最多下单数、时间内重复开仓等
 *
 * 设计思路：Backtesting::_enter_trade 在每次创建 trade 前先过一遍 checkEntryAllowed()，
 * 不通过就返回 ProtectionViolation，Backtesting 将其计入 rejected_signals。
 */
class ProtectionManager
{
    /** @var PairLock[] 所有 pair 锁 */
    private $locks = [];

    /**
     * 锁配置：按 exit 原因配置不同的冷却期（毫秒）
     * 例：['stop_loss' => 3_600_000, 'roi' => 0, 'force_exit' => 0, '*' => 600_000]
     * 其中 '*' 是默认未配置时的回退。
     *
     * @var array<string, int>
     */
    private $coolingByExitReason = [];

    /**
     * 全局默认：未匹配到 exitReason 时的冷却毫秒数。
     * 0 = 不锁。
     */
    private $defaultCoolingMs = 0;

    /**
     * @param array<string, int> $coolingByExitReason ExitType => 毫秒
     * @param int                 $defaultCoolingMs  默认冷却毫秒
     */
    public function __construct(array $coolingByExitReason = [], int $defaultCoolingMs = 0)
    {
        $this->coolingByExitReason = $coolingByExitReason;
        $this->defaultCoolingMs = $defaultCoolingMs;
    }

    /**
     * 在平仓后自动加锁（根据 exit_reason 查表）
     */
    public function lockAfterExit(TradeRecord $trade, int $nowMs): void
    {
        $reason = $trade->getExitReason();
        $ms = $this->coolingByExitReason[$reason] ?? ($this->coolingByExitReason['*'] ?? $this->defaultCoolingMs);
        if ($ms <= 0) {
            return; // 未启用或原因不需要锁
        }
        $this->addLock((string) $trade->getSymbol(), $ms, $nowMs, $reason);
    }

    /**
     * 手工加锁（比如策略 custom_exit 里发现特殊事件，手动锁 24h）
     */
    public function addLock(string $pairStr, int $coolingMs, int $nowMs, string $reason = ''): void
    {
        if ($coolingMs < 0) {
            throw new InvalidArgumentException("coolingMs cannot be negative");
        }
        if ($coolingMs === 0) {
            return;
        }
        $this->locks[] = new PairLock($pairStr, $nowMs + $coolingMs, $nowMs, $reason);
    }

    /**
     * 检查某 pair 在当前时刻是否锁着
     */
    public function isLocked(string $pairStr, int $nowMs): bool
    {
        foreach ($this->locks as $lock) {
            if ($lock->getPair() === $pairStr && $lock->isLockedAt($nowMs)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 开仓前综合校验：所有开仓保护逻辑集中在这一处。
     *
     * 返回 null = 通过；返回字符串 = 拒绝原因（Backtesting 用来计数 rejected_signals）
     *
     * @param TradeRecord[]       $openTrades 当前所有未平仓
     * @param TradingSymbol       $symbol     即将开的 pair
     * @param StrategyInterface   $strategy
     * @param int                 $nowMs      当前时间戳（毫秒）
     * @param bool                $forceEntry 是否是 SIG_FORCE（强制入场可以绕过冷却期）
     */
    public function checkEntryAllowed(
        array $openTrades,
        TradingSymbol $symbol,
        StrategyInterface $strategy,
        int $nowMs,
        bool $forceEntry
    ): ?string {
        $pairStr = (string) $symbol;

        // 1. 全局最大开仓数
        if (count($openTrades) >= $strategy->getMaxOpenTrades()) {
            return "MAX_OPEN_TRADES_REACHED (" . count($openTrades) . "/" . $strategy->getMaxOpenTrades() . ")";
        }

        // 2. Per pair 最大开仓数
        $countOnPair = 0;
        foreach ($openTrades as $t) {
            if ((string) $t->getSymbol() === $pairStr) {
                $countOnPair++;
            }
        }
        if ($countOnPair >= $strategy->getMaxOpenTradesPerPair()) {
            return "MAX_PAIR_TRADES_REACHED ({$countOnPair}/" . $strategy->getMaxOpenTradesPerPair() . " on {$pairStr})";
        }

        // 3. 冷却期锁（强制入场跳过）
        if (!$forceEntry && $this->isLocked($pairStr, $nowMs)) {
            return "PAIR_COOLING_LOCKED ({$pairStr})";
        }

        return null;
    }

    /**
     * 返回目前所有仍然有效的锁（调试/报表用）
     * @param int $nowMs
     * @return PairLock[]
     */
    public function getActiveLocks(int $nowMs): array
    {
        $active = [];
        foreach ($this->locks as $lock) {
            if ($lock->isLockedAt($nowMs)) {
                $active[] = $lock;
            }
        }
        return $active;
    }

    /**
     * 清理过期 lock（每几百根 K 线调一次，避免内存无限增长）
     */
    public function pruneExpired(int $nowMs): void
    {
        $this->locks = array_values(array_filter($this->locks, static function (PairLock $l) use ($nowMs) {
            return $l->isLockedAt($nowMs);
        }));
    }
}
