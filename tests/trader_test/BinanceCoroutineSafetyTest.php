<?php

namespace Sikelan\Tests\trader_test;

use App\Services\Exchanges\Adapters\BinanceExchange;
use App\Services\Exchanges\Formatters\BinanceSymbolFormatter;
use PHPUnit\Framework\TestCase;
use Sikelan\Core\Logger;

/**
 * BinanceExchange 协程安全验证 — 覆盖 2 大并发场景：
 *
 *   🅰 PerCidContextTrait 上下文隔离：
 *     3 个协程（SPOT/USD-M/COIN-M）并发 withMarketContext，故意用 sleep 制造 reverse-resume 顺序，
 *     验证 cid 隔离栈绝不会串协程。
 *
 *   🅑 getServerTimestampMs 一次性初始化：
 *     10 个协程并发首次调 getServerTimestampMs，验证只有 1 个发起 /time HTTP 请求（CAS 兜底），
 *     其余协程等待复用同一份 delta。
 *
 * 两个场景共同验证：BinanceExchange 在多协程下 request() 签名安全、API key 不会串、
 *   sslVerify/testnet 对象属性不会被其他协程的 swap 污染。
 *
 * @requires extension swoole
 */
class BinanceCoroutineSafetyTest extends TestCase
{
    // ------------------------------------------------------------------
    //  常量 + 构造 mock 的工具
    // ------------------------------------------------------------------

    private const CUSTOM_URLS = [
        BinanceExchange::MARKET_SPOT   => 'https://spot-binance.example.com',
        BinanceExchange::MARKET_USD_M  => 'https://usdm-fapi.example.com',
        BinanceExchange::MARKET_COIN_M => 'https://coinm-dapi.example.com',
    ];

    /**
     * 构建只 mock request() 的 BinanceExchange；3 市场配截然不同的 base_url / ssl_verify / testnet。
     *
     * @return array{0: BinanceExchange&\PHPUnit\Framework\MockObject\MockObject, 1: array}  [mock, &$requestCalls]
     */
    private function makeExchangeMock(&$requestCalls)
    {
        $mock = $this->getMockBuilder(BinanceExchange::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request'])
            ->getMock();

        $mock->method('request')->willReturnCallback(
            static function (string $path, string $method, array $query, bool $signed) use (&$requestCalls): array {
                $requestCalls[] = compact('path', 'method', 'query', 'signed');
                return ['symbol' => 'DUMMY', 'price' => '100', 'time' => 1234567890, 'serverTime' => 9999999999];
            }
        );

        $ref = new \ReflectionClass($mock);
        $set = static function (string $p, $v) use ($ref, $mock): void {
            if (!$ref->hasProperty($p)) { return; }
            $rp = $ref->getProperty($p); $rp->setAccessible(true); $rp->setValue($mock, $v);
        };
        $set('symbolFormatter', new BinanceSymbolFormatter());
        $set('logger', new class extends Logger {
            public function __construct() {}
            public function warning($m, array $c = []): void {}
            public function error($m, array $c = []): void {}
            public function debug($m, array $c = []): void {}
        });

        $set('config', [
            'base_url'       => self::CUSTOM_URLS[BinanceExchange::MARKET_SPOT],
            'testnet'        => false,
            'api_key'        => 'k',
            'secret'         => 's',
            'ssl_verify'     => true,
            'rate_limit_ms'  => 0,
            'markets' => [
                BinanceExchange::MARKET_SPOT => [
                    'path_prefix' => '/api/v3',
                    'base_url'    => self::CUSTOM_URLS[BinanceExchange::MARKET_SPOT],
                    'ssl_verify'  => true,
                    'testnet'     => false,
                ],
                BinanceExchange::MARKET_USD_M => [
                    'path_prefix' => '/fapi/v1',
                    'base_url'    => self::CUSTOM_URLS[BinanceExchange::MARKET_USD_M],
                    'testnet_url' => self::CUSTOM_URLS[BinanceExchange::MARKET_USD_M],  // testnet_url 也要配自定义域名，避免 fallback 到 MARKET_DEFAULTS
                    'ssl_verify'  => false,
                    'testnet'     => true,
                ],
                BinanceExchange::MARKET_COIN_M => [
                    'path_prefix' => '/dapi/v1',
                    'base_url'    => self::CUSTOM_URLS[BinanceExchange::MARKET_COIN_M],
                    'ssl_verify'  => true,
                    'testnet'     => false,
                ],
            ],
        ]);
        $set('sslVerify', true);
        $set('testnet',   false);

        $atomic = class_exists(\Swoole\Atomic::class) ? \Swoole\Atomic::class : null;
        $set('lastRequestTimeAtomic', $atomic ? new \Swoole\Atomic(0)
            : new class { public function get(): int { return 0; } public function set(int $v): void {} });

        // serverTimeInitAtomic / serverTimeDelta：场景 A 保持初始态 (0=null)，
        //   让首次 withMarketContext → buildRequest → getServerTimestampMs 走"首次初始化"分支
        //   从而验证 CAS 一次性机制（10 协程并发只会有 1 个发 /time）
        $set('serverTimeInitAtomic', $atomic ? new \Swoole\Atomic(0)
            : new class { public function get(): int { return 0; } public function cmpset(int $o, int $n): bool { return false; } public function set(int $v): void {} });
        $set('serverTimeDelta', null);

        return $mock;
    }

    // ------------------------------------------------------------------
    //  Skip 守卫
    // ------------------------------------------------------------------

    private static function hasSwooleCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false)
            && class_exists(\Swoole\Atomic::class, false);
    }

    public static function setUpBeforeClass(): void
    {
        if (!self::hasSwooleCoroutine()) {
            self::markTestSkipped('协程安全测试需要 Swoole Coroutine + Atomic 扩展，skip。');
        }
    }

    // ------------------------------------------------------------------
    //  辅助：两个反射工具闭包（在 Coroutine::run 静态语境里也能用，因为不依赖 $this）
    // ------------------------------------------------------------------

    /** 返回「读对象属性」闭包 */
    private static function makePropReader(): \Closure
    {
        return static function (object $obj, string $propName) {
            $r = new \ReflectionProperty($obj, $propName);
            $r->setAccessible(true);
            return $r->getValue($obj);
        };
    }

    /** 返回「反射调 protected/private 方法」闭包 */
    private static function makeInvoker(): \Closure
    {
        return static function (object $obj, string $method, array $args = []) {
            $r = new \ReflectionMethod($obj, $method);
            $r->setAccessible(true);
            return $r->invokeArgs($obj, $args);
        };
    }

    /**
     * 临时吞掉 Swoole 启动协程时 PHPUnit 捕获的 Xdebug WARNING。
     */
    private static function swallowXdebugWarning(): void
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            if ($errno === E_WARNING && strpos($errstr, 'Using Xdebug in coroutines') !== false) {
                return true;
            }
            return false;
        });
    }

    // ==================================================================
    //  用例 A：3 协程 reverse-resume 顺序 → 上下文 + 对象属性绝不串
    // ==================================================================

    /**
     * 故意让 3 个协程 push 顺序 A→B→C 但 resume 顺序 C→B→A（sleep 时长 A 最长），
     * 验证 cid 隔离栈 + swap/restore 机制：
     *   · A 的 URL 必须是 SPOT 域名（最后 resume 也不串）
     *   · B 的 sslVerify=false / testnet=true（USD-M 独有配置，swap 后被覆盖）
     *   · C 的 URL 必须是 COIN-M 域名（最先 resume 先做断言）
     *   · 全部跑完后，sslVerify/testnet 对象属性还原回初始值
     */
    public function testContextStackNeverCrossesCoroutinesWithReverseResumeOrder(): void
    {
        $requestCalls = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeExchangeMock($requestCalls);

        // 先手动把 serverTimeInitAtomic 标记为"已初始化"，让 buildRequest 不触发真实 /time 请求
        // （本用例关注的是 withMarketContext 的 cid 隔离，不是 timestamp CAS）
        $ref = new \ReflectionProperty($mock, 'serverTimeInitAtomic');
        $ref->setAccessible(true);
        /** @var \Swoole\Atomic $atomic */
        $atomic = $ref->getValue($mock);
        $atomic->set(2);
        $deltaProp = new \ReflectionProperty($mock, 'serverTimeDelta');
        $deltaProp->setAccessible(true);
        $deltaProp->setValue($mock, 0);

        // 断言初始对象属性值
        $readPropLocal = self::makePropReader();
        $initialSsl = $readPropLocal($mock, 'sslVerify');
        $initialNet = $readPropLocal($mock, 'testnet');
        $this->assertTrue($initialSsl,  '初始 sslVerify=true');
        $this->assertFalse($initialNet, '初始 testnet=false');

        $readProp = self::makePropReader();
        $invoke   = self::makeInvoker();

        // [A_SPOT, B_USDM, C_COINM] 各自的 {url, ssl_verify_in_ctx, testnet_in_ctx}
        $results = [];
        // 捕获协程里任何异常，便于调试
        $errors = [];

        self::swallowXdebugWarning();
        try {
            \Swoole\Coroutine\run(static function () use ($mock, &$results, &$errors, $readProp, $invoke) {
                // 3 个协程：push 顺序 A→B→C，sleep 时长 A 最长 → resume 顺序必然 C→B→A
                go(static function () use ($mock, &$results, &$errors, $readProp, $invoke): void {
                    try {
                        $result = $invoke($mock, 'withMarketContext', [
                            'BTC/USDT',
                            static function () use ($mock, $readProp, $invoke): array {
                                \Swoole\Coroutine::sleep(0.020);
                                $sslVal = $readProp($mock, 'sslVerify');
                                $netVal = $readProp($mock, 'testnet');
                                $nativeSymbol = $mock->formatSymbol('BTC/USDT');
                                $req = $invoke($mock, 'buildRequest', [
                                    '~ticker/price', 'GET', ['symbol' => $nativeSymbol], false,
                                ]);
                                return [
                                    'url'               => (string) ($req['url'] ?? ''),
                                    'ssl_verify_in_ctx' => (bool) $sslVal,
                                    'testnet_in_ctx'    => (bool) $netVal,
                                ];
                            },
                        ]);
                        $results['A_SPOT'] = $result;
                    } catch (\Throwable $e) {
                        $errors['A_SPOT'] = $e->getMessage();
                    }
                });

                go(static function () use ($mock, &$results, &$errors, $readProp, $invoke): void {
                    try {
                        $result = $invoke($mock, 'withMarketContext', [
                            'BTC/USDT:SWAP',
                            static function () use ($mock, $readProp, $invoke): array {
                                \Swoole\Coroutine::sleep(0.010);
                                $sslVal = $readProp($mock, 'sslVerify');
                                $netVal = $readProp($mock, 'testnet');
                                $nativeSymbol = $mock->formatSymbol('BTC/USDT:SWAP');
                                $req = $invoke($mock, 'buildRequest', [
                                    '~ticker/price', 'GET', ['symbol' => $nativeSymbol], false,
                                ]);
                                return [
                                    'url'               => (string) ($req['url'] ?? ''),
                                    'ssl_verify_in_ctx' => (bool) $sslVal,
                                    'testnet_in_ctx'    => (bool) $netVal,
                                ];
                            },
                        ]);
                        $results['B_USDM'] = $result;
                    } catch (\Throwable $e) {
                        $errors['B_USDM'] = $e->getMessage();
                    }
                });

                go(static function () use ($mock, &$results, &$errors, $readProp, $invoke): void {
                    try {
                        $result = $invoke($mock, 'withMarketContext', [
                            'BTC/USD:SWAP',
                            static function () use ($mock, $readProp, $invoke): array {
                                \Swoole\Coroutine::sleep(0.003);
                                $sslVal = $readProp($mock, 'sslVerify');
                                $netVal = $readProp($mock, 'testnet');
                                $nativeSymbol = $mock->formatSymbol('BTC/USD:SWAP');
                                $req = $invoke($mock, 'buildRequest', [
                                    '~ticker/price', 'GET', ['symbol' => $nativeSymbol], false,
                                ]);
                                return [
                                    'url'               => (string) ($req['url'] ?? ''),
                                    'ssl_verify_in_ctx' => (bool) $sslVal,
                                    'testnet_in_ctx'    => (bool) $netVal,
                                ];
                            },
                        ]);
                        $results['C_COINM'] = $result;
                    } catch (\Throwable $e) {
                        $errors['C_COINM'] = $e->getMessage();
                    }
                });
            });
        } finally {
            restore_error_handler();
        }

        // --- 断言 1：每个协程拿到的 URL 域名必须匹配自己 symbol 对应的市场 ---
        $this->assertArrayHasKey('A_SPOT',  $results, '协程 A SPOT 必须返回结果');
        $this->assertArrayHasKey('B_USDM',  $results, '协程 B USD-M 必须返回结果');
        $this->assertArrayHasKey('C_COINM', $results, '协程 C COIN-M 必须返回结果');
        $a = $results['A_SPOT']; $b = $results['B_USDM']; $c = $results['C_COINM'];

        $this->assertStringStartsWith(
            self::CUSTOM_URLS[BinanceExchange::MARKET_SPOT] . '/api/v3/ticker/price',
            $a['url'], 'A(SPOT) URL 必须是 SPOT 域名'
        );
        $this->assertStringStartsWith(
            self::CUSTOM_URLS[BinanceExchange::MARKET_USD_M] . '/fapi/v1/ticker/price',
            $b['url'], 'B(USD-M) URL 必须是 USD-M 域名'
        );
        $this->assertStringStartsWith(
            self::CUSTOM_URLS[BinanceExchange::MARKET_COIN_M] . '/dapi/v1/ticker/price',
            $c['url'], 'C(COIN-M) URL 必须是 COIN-M 域名'
        );

        // --- 断言 2：callback 执行期间读到的对象属性值（sleep 之后 = 其他协程已 push 完 context） ---
        $this->assertTrue($a['ssl_verify_in_ctx'],   'A(SPOT) callback 内 sslVerify 应为 true');
        $this->assertFalse($a['testnet_in_ctx'],     'A(SPOT) callback 内 testnet 应为 false');
        $this->assertFalse($b['ssl_verify_in_ctx'],  'B(USD-M) callback 内 sslVerify 应为 false（USD-M 独有）');
        $this->assertTrue($b['testnet_in_ctx'],      'B(USD-M) callback 内 testnet 应为 true（USD-M 独有）');
        $this->assertTrue($c['ssl_verify_in_ctx'],   'C(COIN-M) callback 内 sslVerify 应为 true');
        $this->assertFalse($c['testnet_in_ctx'],     'C(COIN-M) callback 内 testnet 应为 false');

        // --- 断言 3：协程全部退出后对象属性还原回初始值 ---
        $finalSsl = self::makePropReader()($mock, 'sslVerify');
        $finalNet = self::makePropReader()($mock, 'testnet');
        $this->assertTrue($finalSsl,  '退出后 sslVerify 必须还原为 true（不能留 USD-M 最后写入的 false）');
        $this->assertFalse($finalNet, '退出后 testnet 必须还原为 false（不能留 USD-M 最后写入的 true）');
    }

    // ==================================================================
    //  用例 B：10 协程并发 getServerTimestampMs → 只有 1 个发 /time
    // ==================================================================

    /**
     * 把 serverTimeInitAtomic 重置为 0，然后 10 个协程同时并发调 getServerTimestampMs。
     * 之前的 static $cachedDelta 实现会让每个协程都发现 null 然后发起 getServerTime() → 10 次 HTTP。
     * 新 CAS 实现只会有 1 个协程抢到 0→1，其余 9 个等标记变 2，全部复用同一份 delta。
     */
    public function testGetServerTimestampMsConcurrencyOnlyRequestsTimeOnce(): void
    {
        $requestCalls = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeExchangeMock($requestCalls);

        // 确认初始态：serverTimeInitAtomic=0, serverTimeDelta=null
        $refAtomic = new \ReflectionProperty($mock, 'serverTimeInitAtomic');
        $refAtomic->setAccessible(true);
        /** @var \Swoole\Atomic $atomic */
        $atomic = $refAtomic->getValue($mock);
        $atomic->set(0);

        $refDelta = new \ReflectionProperty($mock, 'serverTimeDelta');
        $refDelta->setAccessible(true);
        $refDelta->setValue($mock, null);

        $invoke = self::makeInvoker();

        $timestamps = [];

        self::swallowXdebugWarning();
        try {
            \Swoole\Coroutine\run(static function () use ($mock, $invoke, &$timestamps) {
                // 10 个协程几乎同时调 getServerTimestampMs
                for ($i = 0; $i < 10; $i++) {
                    go(static function () use ($mock, $invoke, &$timestamps, $i): void {
                        // 稍微错开一点避免调度器极端情况；但不影响 CAS 语义
                        if ($i % 2 === 0) {
                            \Swoole\Coroutine::sleep(0.001);
                        }
                        $ts = $invoke($mock, 'getServerTimestampMs', []);
                        $timestamps[$i] = $ts;
                    });
                }
            });
        } finally {
            restore_error_handler();
        }

        // --- 断言 ---

        // 1. 每个协程都拿到了合法的正整数 timestamp
        foreach ($timestamps as $i => $ts) {
            $this->assertIsInt($ts, "协程 $i 应返回 int timestamp");
            $this->assertGreaterThan(0, $ts, "协程 $i timestamp 应为正数");
        }

        // 2. 所有协程返回的 timestamp 彼此差不超过 1000ms（因为是同一毫秒级时钟 + 同一 delta）
        //    注意 getServerTimestampMs 内部每次会重新调 microtime(true)，所以各协程返回的精确值
        //    会略有差异（几毫秒），但差值一定很小（不会是"一个协程取了服务器时间 10s 前、
        //    另一个没调 server time 直接用了本地时间"这种 10s 级差异）
        $minTs = min($timestamps);
        $maxTs = max($timestamps);
        $this->assertLessThan(1000, $maxTs - $minTs,
            '所有协程返回的 timestamp 应在 1s 范围内（同一份 server time delta + 几乎同时计算）');

        // 3. 只有 1 个 /time HTTP 请求（CAS 保证）。
        //    注意：mock 的 request() 收到原始 path='~time'（buildRequest 内部才会拼成 /api/v3/time）
        $timeRequests = array_filter($requestCalls, static function (array $call): bool {
            return ($call['path'] ?? '') === '~time';
        });
        $this->assertCount(1, $timeRequests,
            '10 协程并发首次调 getServerTimestampMs，只有 1 个应该发起 /time HTTP 请求（CAS 保证）');

        // 4. serverTimeInitAtomic 最终应是 2（已完成），serverTimeDelta 应非 null
        $this->assertSame(2, $atomic->get(), 'serverTimeInitAtomic 最终应为 2');
        $this->assertNotNull($refDelta->getValue($mock), 'serverTimeDelta 最终不应为 null');
    }
}
