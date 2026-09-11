<?php

namespace App\Services\Trader\Protection;

/**
 * Pair 冷却期锁（相当于 Freqtrade PairLocks）
 *
 * 一个 pair 平仓后，在 cool_until_ms 之前不允许再开新仓。
 * 用法：
 *   ProtectionManager 在平仓时调用 lock($pair, 30 * TIMEFRAME_MS, $now)
 *   入场前检查 isLocked($pair, $now)，true 就拒绝开仓。
 */
class PairLock
{
    /** @var string 标准 pair 字符串（如 BTC/USDT）*/
    private $pair;

    /** @var int 锁到期时间（毫秒时间戳）*/
    private $coolUntilMs;

    /** @var int 锁创建时间（毫秒）*/
    private $lockedAt;

    /** @var string 锁原因（ExitType / 自定义字符串）*/
    private $reason;

    public function __construct(string $pair, int $coolUntilMs, int $lockedAt, string $reason = '')
    {
        $this->pair        = $pair;
        $this->coolUntilMs = $coolUntilMs;
        $this->lockedAt    = $lockedAt;
        $this->reason      = $reason;
    }

    public function getPair(): string { return $this->pair; }
    public function getCoolUntilMs(): int { return $this->coolUntilMs; }
    public function getLockedAt(): int { return $this->lockedAt; }
    public function getReason(): string { return $this->reason; }

    /**
     * 是否仍然锁定
     */
    public function isLockedAt(int $timestampMs): bool
    {
        return $timestampMs < $this->coolUntilMs;
    }
}
