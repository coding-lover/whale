<?php

namespace Sikelan\Tests\trader_test;

use App\Services\Exchanges\Adapters\BinanceExchange;
use App\Services\Exchanges\Adapters\OkxExchange;
use App\Services\Exchanges\ExchangeInterface;
use PHPUnit\Framework\TestCase;

/**
 * 验证 ExchangeInterface 新增 startMs/endMs 参数（用于跨 1000 根分页）能被
 * Binance/OKX 适配器正确映射为交易所原生 query 参数。
 *
 *  ⭐ 根因回归：用户首次跑 1m 10d 命令时 15 次分页全是「最近 1000 根」
 *  → 重叠 → CSV 去重后只剩 1000 根（覆盖仅 6.94%），本质就是：
 *     「getKlines 没把 startMs/endMs 传进交易所 query」。
 * 本测试保证这个问题不会再回归（任何把 startMs/endMs 参数从 getKlines 签名
 *  删掉的 commit 都会让适配器 buildParam 分支逻辑失效，测试失败）。
 *
 * Mock 策略：对 BinanceExchange/OKXExchange 调用 getKlines 时，
 *   拦截 protected method request(...)（Adapter 内调用 protected）
 *   → 改成 PHPUnit createPartialMock 仅 mock request，其他真实执行
 *   → 在 mock 返回原始数据后，getKlines 走 normalizeKlines → 返回结果不重要
 *   → 我们关心的是「传给 request 第 3 个参数的 query 数组里是否有正确的 key」。
 *
 * @package Sikelan\Tests\trader_test
 */
class GetKlinesTimeWindowParamsTest extends TestCase
{
    // ------------------------------------------------------------------
    //  工具：构造一个只 mock request() 的 Binance/OKX 适配器
    // ------------------------------------------------------------------

    /**
     * @param string $class BinanceExchange::class 或 OkxExchange::class
     * @param array $capturedRef 传引用；mock request() 时会把 (method, path, query, signFlag) 追加进来
     */
    private function makeAdapterMock(string $class, array &$capturedRef)
    {
        // AbstractExchange 构造签名::__construct(LoggerInterface $logger, Config $config, HttpClientInterface $client)
        // partialMock 不调用构造函数，避免依赖装配；内部 formatSymbol 依赖 symbolFormatter 属性，
        // 因此我们手动赋值这 3 个依赖的 mock（调用时 formatSymbol/formatInterval 用真实方法没问题）。
        $mock = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request'])
            ->getMock();

        $mock->method('request')->willReturnCallback(
            function (string $path, string $method, array $query, bool $signFlag) use (&$capturedRef): array {
                $capturedRef[] = ['path' => $path, 'method' => $method, 'query' => $query, 'sign' => $signFlag];
                // normalizeKlines 需要二维数组 + 每行 ≥6 项：
                // Binance normalize 期望每项为 [$ts, $o, $h, $l, $c, $v, ...]
                return [
                    ['1700000000000', '100', '101', '99', '100.5', '50', 'ignore'],
                    ['1700000060000', '100.5', '102', '100', '101',   '60', 'ignore'],
                ];
            }
        );

        // 给 mock 注入 symbolFormatter（formatSymbol 用）。Binance 与 OKX 分别 new。
        $reflection = new \ReflectionClass($mock);
        $setProps = static function (string $prop, $value) use ($reflection, $mock) {
            if (!$reflection->hasProperty($prop)) {
                return;
            }
            $p = $reflection->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($mock, $value);
        };

        if ($class === BinanceExchange::class) {
            $setProps('symbolFormatter', new \App\Services\Exchanges\Formatters\BinanceSymbolFormatter());
        } elseif ($class === OkxExchange::class) {
            $setProps('symbolFormatter', new \App\Services\Exchanges\Formatters\OkxSymbolFormatter());
        }
        return $mock;
    }

    // ------------------------------------------------------------------
    //  Binance 参数映射：startMs → startTime、endMs → endTime
    // ------------------------------------------------------------------

    public function testBinanceDefaultGetKlinesNoTimeParams(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeAdapterMock(BinanceExchange::class, $captured);
        $rows = $mock->getKlines('BTC/USDT', '1h', 500);
        $this->assertIsArray($rows);
        $this->assertCount(1, $captured);
        $query = $captured[0]['query'];

        // 基础三项必须在
        $this->assertSame('BTCUSDT', $query['symbol'] ?? null);
        $this->assertSame('1h',      $query['interval'] ?? null);
        $this->assertSame(500,       $query['limit'] ?? null);
        // 没传 start/end 时不应出现
        $this->assertArrayNotHasKey('startTime', $query, 'startMs 为 null 时，Binance query 不应包含 startTime');
        $this->assertArrayNotHasKey('endTime',   $query, 'endMs 为 null 时，Binance query 不应包含 endTime');
        // 固定 REST 路径
        $this->assertSame('/api/v3/klines', $captured[0]['path']);
    }

    public function testBinanceGetKlinesMapsStartAndEndMsToRestParams(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeAdapterMock(BinanceExchange::class, $captured);
        $mock->getKlines('ETH/USDT', '1m', 1000, 1_700_000_000_000, 1_700_059_999_999);

        $this->assertCount(1, $captured);
        $query = $captured[0]['query'];
        $this->assertSame(1_700_000_000_000, $query['startTime'] ?? null, 'startMs 必须映射为 Binance 的 startTime');
        $this->assertSame(1_700_059_999_999, $query['endTime']   ?? null, 'endMs   必须映射为 Binance 的 endTime');
        $this->assertFalse($captured[0]['sign'], '/klines 属于公开 REST，不得签名（sign=false）');
    }

    // ------------------------------------------------------------------
    //  OKX 参数映射：startMs → after、endMs → before（注意方向反的）
    // ------------------------------------------------------------------

    public function testOkxDefaultGetKlinesNoBeforeAfter(): void
    {
        $captured = [];
        /** @var OkxExchange $mock */
        $mock = $this->makeAdapterMock(OkxExchange::class, $captured);
        $rows = $mock->getKlines('BTC/USDT:SWAP', '4h', 300);
        $this->assertIsArray($rows);
        $this->assertCount(1, $captured);
        $q = $captured[0]['query'];
        $this->assertSame('BTC-USDT-SWAP', $q['instId'] ?? null);
        $this->assertSame('4H',            $q['bar']    ?? null,   'OKX bar 大写（formatInterval 行为）');
        $this->assertSame(300,             $q['limit']  ?? null);
        $this->assertArrayNotHasKey('after',  $q, 'startMs=null → OKX 不能带 after');
        $this->assertArrayNotHasKey('before', $q, 'endMs=null   → OKX 不能带 before');
        $this->assertSame('/api/v5/market/candles', $captured[0]['path']);
    }

    public function testOkxGetKlinesMapsStartEndToAfterBeforeInverted(): void
    {
        $captured = [];
        /** @var OkxExchange $mock */
        $mock = $this->makeAdapterMock(OkxExchange::class, $captured);
        // startMs=1000(老) endMs=2000(新) → OKX after=1000 before=2000
        $mock->getKlines('BTC/USDT', '15m', 100, 1000, 2000);

        $q = $captured[0]['query'];
        $this->assertSame('1000', $q['after']  ?? null, 'OKX：startMs 映射为 after（方向：数字更小 = 更久远的过去起点，OKX 用 after 这个名字反直觉）');
        $this->assertSame('2000', $q['before'] ?? null, 'OKX：endMs   映射为 before（数字更大 = 更接近现在，OKX 用 before 反直觉）');
    }

    // ------------------------------------------------------------------
    //  ExchangeInterface 契约检查：签名含 startMs/endMs（防止有人回滚改签名）
    // ------------------------------------------------------------------

    public function testExchangeInterfaceSignatureIncludesStartMsEndMs(): void
    {
        $reflection = new \ReflectionMethod(ExchangeInterface::class, 'getKlines');
        $params = $reflection->getParameters();
        $names = array_map(static fn(\ReflectionParameter $p) => $p->getName(), $params);
        $this->assertSame(
            ['symbol', 'interval', 'limit', 'startMs', 'endMs'],
            $names,
            'ExchangeInterface::getKlines 参数顺序必须为 (symbol, interval, limit, ?startMs, ?endMs)，以保持 trader 命令分页契约稳定'
        );
        // startMs / endMs 必须允许为 null
        $startMsParam = $params[3];
        $endMsParam   = $params[4];
        $this->assertTrue($startMsParam->allowsNull(), 'startMs 必须允许为 null');
        $this->assertTrue($endMsParam->allowsNull(),   'endMs   必须允许为 null');
        // 默认值都必须是 null（向后兼容：老调用方不传也 ok）
        $this->assertNull($startMsParam->getDefaultValue());
        $this->assertNull($endMsParam->getDefaultValue());
    }
}
