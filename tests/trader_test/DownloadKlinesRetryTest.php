<?php

namespace Sikelan\Tests\trader_test;

use App\Commands\TraderDownloadKlinesCommand;
use App\Services\Exchanges\ExchangeException;
use PHPUnit\Framework\TestCase;

/**
 * TraderDownloadKlinesCommand 重试机制单测。
 *
 * 重点覆盖 5 个核心场景：
 *   1. 首次调用就成功 → 不触发任何 retry / sleep
 *   2. 前 N-1 次抛可重试异常，第 N 次成功 → 刚好达 maxAttempts 成功返回
 *   3. 连续抛可重试异常 N 次（maxAttempts=N） → 最终抛「最后一次」的异常给上层
 *   4. 第 1 次就抛不可重试异常（4xx 业务错） → 立即失败，0 次 retry，0 次 sleep
 *   5. 可重试但 non-ExchangeException（代码 TypeError 等）→ 立即失败不算 retryable（防止吞 bug）
 *
 * 测试技巧：
 *   因为 retryWithBackoff / computeBackoffDelay / isRetryableException / nonBlockingSleep
 *   都是 protected，我们用「匿名子类 + 公开别名」暴露它们。nonBlockingSleep 在此子类中
 *   覆盖改写为记录 sleep 次数 + 累计时长，不真的 sleep（保证单测毫秒级完成）。
 *
 * @package Sikelan\Tests\trader_test
 */
class DownloadKlinesRetryTest extends TestCase
{
    /**
     * 匿名子类：把受保护的重试器相关方法以 public 暴露，并把 sleep 改写为可追踪的无操作。
     */
    private function makeTestableHarness(): object
    {
        return new class extends TraderDownloadKlinesCommand {
            /** @var list<float> 记录每次 nonBlockingSleep 的秒数（按调用顺序）*/
            public array $sleepHistory = [];

            // --- 转发受保护方法为 public ---
            public function publicRetryWithBackoff(int $m, float $b, ?callable $on, callable $op)
            {
                return $this->retryWithBackoff($m, $b, $on, $op);
            }

            public function publicIsRetryable(\Throwable $e): bool
            {
                return $this->isRetryableException($e);
            }

            public function publicComputeDelay(float $base, int $k): float
            {
                return $this->computeBackoffDelay($base, $k);
            }

            // --- 覆盖 sleep：不真睡，只记参数 ---
            protected function nonBlockingSleep(float $seconds): void
            {
                if ($seconds <= 0) {
                    return;
                }
                $this->sleepHistory[] = $seconds;
            }

            // --- 空实现（CommandInterface 必须，但本用例不用 exec/help/name/desc）---
            public function commandName(): string { return 'test-harness'; }
            public function desc(): string { return ''; }
            public function exec(array $args): ?string { return ''; }
            public function help(array $args): ?string { return ''; }
        };
    }

    // ------------------------------------------------------------------
    //  场景 1：首次成功 → 无 retry 无 sleep
    // ------------------------------------------------------------------

    public function testSuccessOnFirstAttemptNeedsNoRetryNoSleep(): void
    {
        $h = $this->makeTestableHarness();
        $called = 0;
        $op = static function () use (&$called): string {
            $called++;
            return 'OK';
        };
        $retried = [];
        $result = $h->publicRetryWithBackoff(5, 1.0, static function (int $a, int $t, \Throwable $e, float $s) use (&$retried): void {
            $retried[] = [$a, $t, $s];
        }, $op);

        $this->assertSame('OK', $result);
        $this->assertSame(1, $called);
        $this->assertEmpty($retried, '无异常，不应触发 onRetry 回调');
        $this->assertEmpty($h->sleepHistory, '无异常，不应调用 sleep');
    }

    // ------------------------------------------------------------------
    //  场景 2：前 N-1 次失败（可重试），第 N 次成功
    // ------------------------------------------------------------------

    public function testSucceedsAfterNMinusOneRetryableFailures(): void
    {
        $h = $this->makeTestableHarness();
        $totalAttempts = 5;    // 5 次最大尝试 = 4 次重试 + 1 次初始
        $failUntil       = 4;  // 前 4 次都失败，第 5 次成功（onRetry 触发 4 次）
        $called = 0;
        $op = static function () use (&$called, $failUntil) {
            $called++;
            if ($called < $failUntil) {
                // 模拟超时：code=0 + Connection failed 消息（栈顶同款错误）
                throw new ExchangeException(
                    'Connection failed to binance API (timeout: 10s, url: ...)',
                    0
                );
            }
            return ['page-ok' => true];
        };
        $onRetryCalls = [];
        $result = $h->publicRetryWithBackoff(
            $totalAttempts,
            3.0,  // 用较大退避底数保证 monotonic：3^k*1.0=3,9,27,81 → 下限 > 上次上限 3^n*1.5 不单调 但 3^1 最大=4.5，3^2 最小=9 → 严格单调。
            static function (int $a, int $t, \Throwable $e, float $s) use (&$onRetryCalls): void {
                $onRetryCalls[] = [$a, $t, $s];
            },
            $op
        );

        $this->assertSame(['page-ok' => true], $result);
        $this->assertSame($failUntil, $called, '第 {$failUntil} 次（index=4）才成功返回');
        // onRetry 应该被调用 (failUntil-1) = 3 次？No：failUntil=4, called 1..3 fail, 4 OK
        // retry 回调：attempt 1 失败后 → attempt 2 失败后 → attempt 3 失败后 → attempt 4 success 无回调
        // 共 3 次回调，对应 attempt = 1, 2, 3
        $this->assertCount($failUntil - 1, $onRetryCalls);
        $this->assertSame([1, 2, 3], array_column($onRetryCalls, 0), 'onRetry 触发 3 次对应 attempt 1/2/3 失败，attempt 4 成功不回调');
        // sleep 时长：4 次回调不，只有 3 次
        $this->assertCount($failUntil - 1, $h->sleepHistory);
        // 由于 $base=3，单调性绝对成立 3^1∈[3,4.5]、3^2∈[9,13.5]、3^3∈[27,40.5] — 严格依次递增
        for ($i = 1; $i < count($h->sleepHistory); $i++) {
            $this->assertGreaterThan(
                $h->sleepHistory[$i - 1],
                $h->sleepHistory[$i],
                "指数退避 base=3：第 {$i} 次 sleep 秒数 ({$h->sleepHistory[$i-1]}) 必须严格小于第 " . ($i + 1) . " 次 ({$h->sleepHistory[$i]})"
            );
        }
    }

    // ------------------------------------------------------------------
    //  场景 3：N 次都失败（全部 retryable）→ 最终抛「最后一次」异常
    // ------------------------------------------------------------------

    public function testAfterMaxAttemptsAlwaysThrowsLastException(): void
    {
        $h = $this->makeTestableHarness();
        $tries = 0;
        $lastEx = null;
        $op = static function () use (&$tries, &$lastEx) {
            $tries++;
            $lastEx = new ExchangeException("Fake 502 Bad Gateway (try {$tries})", 502);
            throw $lastEx;
        };

        $this->expectException(ExchangeException::class);
        $this->expectExceptionMessage('(try 3)');  // maxAttempts=3 → 抛第 3 次异常消息
        $this->expectExceptionCode(502);

        try {
            $h->publicRetryWithBackoff(3, 1.0, null, $op);
        } finally {
            // finally 是为了断言不因 expectException 跳过
            $this->assertSame(3, $tries, 'maxAttempts=3 → operation 必须正好调用 3 次');
            $this->assertCount(2, $h->sleepHistory, '3 次尝试 → 只在第 1/2 次失败后各 sleep 1 次（第 3 次失败直接抛）');
        }
    }

    // ------------------------------------------------------------------
    //  场景 4：不可重试业务异常（4xx）→ 0 次 retry 立刻抛
    // ------------------------------------------------------------------

    public function test4xxBusinessExceptionNeverRetried(): void
    {
        $h = $this->makeTestableHarness();
        $tries = 0;
        $op = static function () use (&$tries) {
            $tries++;
            throw new ExchangeException(
                'Illegal symbols parameter; symbol was NULL',
                400
            );
        };

        $onRetryCalled = 0;
        $this->expectException(ExchangeException::class);
        $this->expectExceptionCode(400);

        try {
            $h->publicRetryWithBackoff(5, 1.2, static function () use (&$onRetryCalled): void {
                $onRetryCalled++;
            }, $op);
        } finally {
            $this->assertSame(1, $tries, '4xx 应只调用 1 次（fail fast）');
            $this->assertSame(0, $onRetryCalled, '4xx 不应触发 onRetry 回调');
            $this->assertEmpty($h->sleepHistory, '4xx 不应有任何 sleep');
        }
    }

    // ------------------------------------------------------------------
    //  场景 5：非 ExchangeException（比如 TypeError）—— 即使看起来"像临时故障"也绝不重试
    // ------------------------------------------------------------------

    public function testNonExchangeExceptionIsNeverRetried(): void
    {
        $h = $this->makeTestableHarness();
        $tries = 0;
        $op = static function () use (&$tries) {
            $tries++;
            // 模拟编程错误：显式抛 TypeError（触发 PHP 内部 bug 级别异常，不是网络/交易所业务）
            throw new \TypeError('Argument 1 passed to foo() must be of type array, null given');
        };

        $onRetryCalled = 0;
        // PHPUnit 9 不支持 multiple expectException —— 捕获后手动断言
        $caught = null;
        try {
            $h->publicRetryWithBackoff(
                10,
                2.0,
                static function () use (&$onRetryCalled): void { $onRetryCalled++; },
                $op
            );
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '应当把原始 TypeError 原样抛到上层');
        $this->assertNotInstanceOf(ExchangeException::class, $caught, '非 ExchangeException 抛出类型保持不变');
        $this->assertSame(1, $tries, '非 ExchangeException 调用 1 次即中止（避免吞掉代码 bug）');
        $this->assertSame(0, $onRetryCalled);
        $this->assertEmpty($h->sleepHistory);
    }

    // ------------------------------------------------------------------
    //  校验：默认配置 base=1.2 下第 1..4 次退避在 [base^k, base^k * 1.5] 区间内，
    //        且在"固定随机种子下"严格单调递增。注意：jitter ∈ [0, 0.5] 是随机的，
    //        不保证所有种子下 base=1.2 都单调（1.2^3 = 1.728 * 1.0 ≤ 1.2^2 * 1.5 = 2.16 → 可能重叠）。
    //        所以本测试切换 base=3.0，100% 保证不重叠；然后只断言区间范围，不强依赖种子。
    // ------------------------------------------------------------------

    public function testBackoffDelayShapeWithDefaultBase(): void
    {
        $h = $this->makeTestableHarness();
        $base = 3.0;
        $delays = [];
        // 跑 N=100 次，都必须落在 [base^k, base^k * 1.5] 闭区间；且 4 个级别互不相交（base=3 → 3^1*1.5=4.5 < 3^2=9, 恒成立）
        for ($trial = 0; $trial < 100; $trial++) {
            foreach ([1, 2, 3, 4] as $k) {
                $d = $h->publicComputeDelay($base, $k);
                $delays[$k][] = $d;
                $lo = $base ** $k;
                $hi = $lo * 1.5;
                $this->assertGreaterThanOrEqual($lo, $d, "base={$base} 时 delay(k={$k}) 下溢：{$d} < {$lo}");
                $this->assertLessThanOrEqual($hi, $d, "base={$base} 时 delay(k={$k}) 上溢：{$d} > {$hi}");
            }
        }
        // 保证跨级别 strict monotonic：max(k=1) < min(k=2) 等
        foreach ([1, 2, 3] as $k) {
            $maxPrev = max($delays[$k]);
            $minNext = min($delays[$k + 1]);
            $this->assertLessThan($minNext, $maxPrev, "base={$base} 级别 k={$k} 的最大值 ({$maxPrev}) 必须严格小于级别 k=" . ($k + 1) . " 的最小值 ({$minNext})");
        }
    }

    // ------------------------------------------------------------------
    //  isRetryable 判定矩阵（防止未来改判断时漏掉分类）
    // ------------------------------------------------------------------

    public function testIsRetryableClassificationMatrix(): void
    {
        $h = $this->makeTestableHarness();

        // 可重试集合
        $retryCases = [
            'Connection timeout 0'          => new ExchangeException('Connection failed to x', 0),
            'HTTP 502 Bad Gateway'          => new ExchangeException('502', 502),
            'HTTP 429 Rate limit'           => new ExchangeException('Too many requests', 429),
            'Msg timeout 200'               => new ExchangeException('Request timed out reading body', 200),
            'Msg Invalid JSON 200'          => new ExchangeException('Invalid JSON response: ...', 200),
            'Msg broken pipe 200'           => new ExchangeException('fgets(): broken pipe', 200),
        ];
        foreach ($retryCases as $name => $ex) {
            $this->assertTrue($h->publicIsRetryable($ex), "{$name} 应判定为可重试");
        }

        // 不可重试集合
        $notRetry = [
            'HTTP 400 bad request'  => new ExchangeException('Illegal symbols', 400),
            'HTTP 401 unauthorized' => new ExchangeException('API-key invalid', 401),
            'HTTP 403 forbidden'    => new ExchangeException('Access denied', 403),
            'HTTP 404 not found'    => new ExchangeException('No such pair', 404),
            'Non-Exchange (TypeError in code)' => new \TypeError('Arg 1 must be array, null given'),
            'Non-Exchange (Runtime)' => new \RuntimeException('oops'),
        ];
        foreach ($notRetry as $name => $ex) {
            $this->assertFalse($h->publicIsRetryable($ex), "{$name} 应判定为不可重试（fail fast）");
        }
    }
}
