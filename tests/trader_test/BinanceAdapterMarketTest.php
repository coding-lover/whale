<?php

namespace Sikelan\Tests\trader_test;

use App\Services\Exchanges\Adapters\BinanceExchange;
use App\Services\Exchanges\ExchangeException;
use App\Services\Exchanges\Formatters\BinanceSymbolFormatter;
use PHPUnit\Framework\TestCase;
use Sikelan\Core\Logger;

/**
 * BinanceExchange 三市场（现货 / U本位 / 币本位）路由与字段映射单元测试。
 *
 * 验证核心 Bug 修复效果：
 *   · 老代码硬编码 api.binance.com + /api/v3/* 导致 SWAP / COIN-M 永续 / 交割
 *     符号全部 404 或 "Invalid symbol"；修复后 3 市场分别走 api / fapi / dapi。
 *   · 配置层（config/exchanges.php）binance.markets.{spot,usd_m,coin_m} 优先级正确。
 *   · ssl_verify / testnet 切换没有 gap。
 *   · COIN-M 订单自动注入 pair、Futures 参数 key 重命名（reduce_only→reduceOnly 等）。
 *   · 标准化响应 normalize*() 可以同时接受 Spot / USD-M / COIN-M 三种原生字段。
 *
 * Mock 策略：
 *   1) 对「URL / path_prefix 精确性」测试，用反射直接调 BinanceExchange::buildRequest()
 *      （Protected 级）然后断言返回数组的 url 字段。这样能验证 buildRequest 内部拼接
 *      path_prefix 逻辑，无需走真正的 HTTP。
 *   2) 对「公共 API 方法（getTicker 等）→ 内部传给 request() 的 query 是否包含正确字段、
 *      订单参数 key 重命名、pair 注入」测试，用 PHPUnit createPartialMock 仅 mock request()，
 *      捕获 (path, method, query, signed) 四元组后做断言。
 *
 * @package Sikelan\Tests\trader_test
 */
class BinanceAdapterMarketTest extends TestCase
{
    // 用于 mock request 时返回的「标准化假响应」（不同方法形状不同）
    private const FAKE_KLINES = [
        ['1700000000000', '100', '101', '99', '100.5', '50'],
        ['1700000060000', '100.5', '102', '100', '101', '60'],
    ];
    private const FAKE_TICKER = ['symbol' => 'BTCUSDT', 'price' => '42000.12', 'time' => 1700000000000];
    private const FAKE_DEPTH  = ['lastUpdateId' => 123, 'bids' => [['41999', '0.1']], 'asks' => [['42001', '0.2']]];
    private const FAKE_TRADES = [['id' => 1, 'price' => '42000', 'qty' => '0.1', 'time' => 1700000000000, 'isBuyerMaker' => false]];

    // ------------------------------------------------------------------
    //  工具 1：构造只 mock request() 的 BinanceExchange，并注入必要属性
    // ------------------------------------------------------------------

    /**
     * @param array $capturedRef 引用；mock request 会把所有调用项 (path, method, query, sign) 追加进来
     * @param array $extraConfig 覆盖注入到 $mock->config 的附加配置（测试 per-market config 覆盖时用）
     * @param callable|null $requestResponder 可选：自定义 request() 返回值工厂；签名 fn(path,method,query,sign):array
     * @return BinanceExchange&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeBinanceMock(
        array &$capturedRef,
        array $extraConfig = [],
        ?callable $requestResponder = null
    ) {
        $mock = $this->getMockBuilder(BinanceExchange::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request'])
            ->getMock();

        // 默认 request 返回：根据调用 path 简单返回对应的假响应形状
        $defaultResponder = static function (string $path) {
            if (strpos($path, 'klines') !== false) { return self::FAKE_KLINES; }
            if (strpos($path, 'depth')  !== false) { return self::FAKE_DEPTH; }
            if (strpos($path, 'trades') !== false) { return self::FAKE_TRADES; }
            if (strpos($path, 'time')   !== false) { return ['serverTime' => 1700000000123]; }
            if (strpos($path, 'balance') !== false || strpos($path, 'account') !== false) {
                return ['balances' => [['asset' => 'USDT', 'free' => '100', 'locked' => '0']]];
            }
            // order / openOrders / ticker 默认
            return self::FAKE_TICKER + ['orderId' => 123, 'status' => 'FILLED', 'type' => 'LIMIT', 'side' => 'BUY',
                'origQty' => '0.1', 'executedQty' => '0.1', 'price' => '42000'];
        };

        $mock->method('request')->willReturnCallback(
            static function (string $path, string $method, array $query, bool $sign)
                use (&$capturedRef, $requestResponder, $defaultResponder): array {
                    $capturedRef[] = compact('path', 'method', 'query', 'sign');
                    if ($requestResponder !== null) {
                        return $requestResponder($path, $method, $query, $sign);
                    }
                    return $defaultResponder($path);
                }
        );

        // 注入 Binance 依赖的属性：symbolFormatter / config / logger / lastRequestTimeAtomic
        //   （disableOriginalConstructor 所以这些都要手动给，不然调用 formatSymbol / withMarketContext 会炸）
        $reflection = new \ReflectionClass($mock);
        $set = static function (string $prop, $value) use ($reflection, $mock): void {
            if (!$reflection->hasProperty($prop)) { return; }
            $p = $reflection->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($mock, $value);
        };
        $set('symbolFormatter', new BinanceSymbolFormatter());
        // Logger 需要一个实例（getBalance 出错会调用 $this->logger->warning），这里用一个空 Logger stub
        $loggerStub = new class extends Logger {
            // 构造一个最小化 Logger：父类 Logger::__construct 接受 Config，这里简化绕过
            public function __construct() {}
            public function warning($message, array $context = []): void {}
            public function error($message, array $context = []): void {}
            public function debug($message, array $context = []): void {}
        };
        $set('logger', $loggerStub);

        // 注入 config：合并默认三市场配置 + 调用方覆盖
        $defaultConfig = [
            'base_url'       => 'https://api.binance.com',
            'testnet'        => false,
            'testnet_url'    => 'https://testnet.binance.vision',
            'api_key'        => 'default-key',
            'secret'         => 'default-secret',
            'ssl_verify'     => true,
            'rate_limit_ms'  => 0,
            'markets' => [
                'spot'   => ['path_prefix' => '/api/v3'],
                'usd_m'  => ['path_prefix' => '/fapi/v1',
                             'base_url' => 'https://fapi.binance.com',
                             'testnet_url' => 'https://demo-fapi.binance.com',
                             'ssl_verify' => true,
                             'api_key' => null, 'secret' => null,
                ],
                'coin_m' => ['path_prefix' => '/dapi/v1',
                             'base_url' => 'https://dapi.binance.com',
                             'testnet_url' => 'https://demo-dapi.binance.com',
                             'ssl_verify' => true,
                             'api_key' => null, 'secret' => null,
                ],
            ],
        ];
        $merged = array_replace_recursive($defaultConfig, $extraConfig);
        $set('config', $merged);

        // lastRequestTimeAtomic（AbstractExchange 构造时 new Swoole\Atomic(0)，但 disableOriginalConstructor 没跑）
        if (class_exists(\Swoole\Atomic::class)) {
            $set('lastRequestTimeAtomic', new \Swoole\Atomic(0));
        } else {
            // 非 Swoole 环境（少见于 CLI 跑 phpunit）给个 stdClass 占位，代码里没用到 atomic 就不会炸
            $set('lastRequestTimeAtomic', new class { public function get(): int { return 0; } public function set(int $v): void {} });
        }

        // sslVerify / testnet 对象属性（AbstractExchange 构造时初始化）
        $set('sslVerify', true);
        $set('testnet', false);

        // serverTimeInitAtomic / serverTimeDelta（BinanceExchange 构造时初始化，disableOriginalConstructor 没跑）
        if (class_exists(\Swoole\Atomic::class)) {
            $set('serverTimeInitAtomic', new \Swoole\Atomic(2));  // 测试场景跳过初始化，直接标记"已完成"
        } else {
            $set('serverTimeInitAtomic', new class { public function get(): int { return 2; } public function cmpset(int $o, int $n): bool { return false; } public function set(int $v): void {} });
        }
        $set('serverTimeDelta', 0);

        return $mock;
    }

    //  工具 2：通过反射调用 protected/private 方法
    private function invokeProtected(object $obj, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }
    private function getProtectedProp(object $obj, string $prop)
    {
        $r = new \ReflectionProperty($obj, $prop);
        $r->setAccessible(true);
        return $r->getValue($obj);
    }

    // ==================================================================
    //  A. 市场路由 resolveMarket / 最终 URL 拼接（3 个市场 × 相对 path）
    // ==================================================================

    /**
     * 3 个代表性符号 → resolveMarket → buildRequest('~time') → URL 必须落在对应域名/prefix
     *
     * @dataProvider provideMarketResolveCases
     */
    public function testResolveMarketAndBuildRequestUrl(string $symbol, string $expectedMarket, string $expectedBaseUrl, string $expectedPrefix): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured);

        // 用反射直接调 protected buildRequest；但它需要 withMarketContext 栈顶注入市场
        // → 所以通过一个临时「包装方法」（再反射）进入 context 内再调 buildRequest
        $result = $this->invokeProtected($mock, 'withMarketContext', [$symbol, function () use ($mock) {
            // 这里已经在对应 market 的 context 栈顶内
            return $this->invokeProtected($mock, 'buildRequest', ['~time', 'GET', [], false]);
        }]);

        $this->assertIsArray($result);
        $this->assertSame($expectedBaseUrl . $expectedPrefix . '/time', $result['url'],
            "symbol={$symbol} market={$expectedMarket}：URL 域名/prefix 不对");
    }

    public function provideMarketResolveCases(): array
    {
        return [
            // 现货（默认，没有 :TYPE）
            'BTC/USDT spot'        => ['BTC/USDT',        BinanceExchange::MARKET_SPOT,
                                       'https://api.binance.com',  '/api/v3'],
            // U本位永续（SWAP + quote=USDT）
            'BTC/USDT:SWAP usd_m'  => ['BTC/USDT:SWAP',   BinanceExchange::MARKET_USD_M,
                                       'https://fapi.binance.com', '/fapi/v1'],
            // U本位交割（带 YYMMDD）
            'BTC/USDT:FUT-250627 usd_m' => ['BTC/USDT:FUT-250627', BinanceExchange::MARKET_USD_M,
                                       'https://fapi.binance.com', '/fapi/v1'],
            // COIN-M 永续（SWAP + quote=USD → 原生 BTCUSD_PERP → dapi）
            'BTC/USD:SWAP coin_m'  => ['BTC/USD:SWAP',    BinanceExchange::MARKET_COIN_M,
                                       'https://dapi.binance.com', '/dapi/v1'],
            // COIN-M 季度交割
            'BTC/USD:QUARTER coin_m' => ['BTC/USD:QUARTER', BinanceExchange::MARKET_COIN_M,
                                       'https://dapi.binance.com', '/dapi/v1'],
        ];
    }

    /**
     * 绝对 path（/fapi/v1/xxx）会被 buildRequest 保留原样，不拼前缀（给 getBalance 里的 /fapi/v2/balance 用）
     */
    public function testAbsolutePathsBypassPrefixInjection(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured);

        $req = $this->invokeProtected($mock, 'withMarketContext',
            [BinanceExchange::MARKET_USD_M, function () use ($mock) {
                // 传入绝对路径 /fapi/v2/balance（buildRequest 遇到 '/' 开头就不拼 prefix）
                return $this->invokeProtected($mock, 'buildRequest', ['/fapi/v2/balance', 'GET', [], false]);
            }]);
        $this->assertStringStartsWith('https://fapi.binance.com/fapi/v2/balance', $req['url']);

        // COIN-M /dapi/v1/balance
        $req = $this->invokeProtected($mock, 'withMarketContext',
            [BinanceExchange::MARKET_COIN_M, function () use ($mock) {
                return $this->invokeProtected($mock, 'buildRequest', ['/dapi/v1/balance', 'GET', [], false]);
            }]);
        $this->assertStringStartsWith('https://dapi.binance.com/dapi/v1/balance', $req['url']);
    }

    /**
     * 顶层配置（SPOT 默认）→ 覆盖 MARKET_DEFAULTS；
     * usd_m.ssl_verify=false 覆盖顶层 ssl_verify=true；
     * → 进入该 market context 后，对象的 sslVerify 属性必须变成 false（sendHttpRequest 用它）。
     */
    public function testPerMarketConfigWinsAndSwapsObjectProps(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured, [
            'ssl_verify' => true,                // 顶层：开（默认 SPOT 用）
            'markets' => [
                'usd_m' => ['ssl_verify' => false, 'testnet' => true,
                            'base_url' => 'https://custom-fapi.example.com'],
            ],
        ]);

        // --- SPOT context：sslVerify 保持 true ---
        $spotSsl = $this->invokeProtected($mock, 'withMarketContext',
            [BinanceExchange::MARKET_SPOT, function () use ($mock) {
                return $this->getProtectedProp($mock, 'sslVerify');
            }]);
        $this->assertTrue($spotSsl, 'SPOT ssl_verify 应当继承顶层=true');

        // --- USD-M context：sslVerify=false 且 testnet=true（从栈顶取）---
        [$futSsl, $futTestnet, $futUrl] = $this->invokeProtected($mock, 'withMarketContext',
            [BinanceExchange::MARKET_USD_M, function () use ($mock) {
                $req = $this->invokeProtected($mock, 'buildRequest', ['~ticker/price', 'GET', [], false]);
                return [
                    $this->getProtectedProp($mock, 'sslVerify'),
                    $this->getProtectedProp($mock, 'testnet'),
                    $req['url'],
                ];
            }]);
        $this->assertFalse($futSsl,    'USD-M 市场 ssl_verify=false 必须覆盖到对象属性，否则 sendHttpRequest 仍会验证证书');
        $this->assertTrue($futTestnet, 'USD-M 市场 testnet=true 必须同步到对象属性');

        // testnet=true → 用 testnet_url（配置没写的 demo-fapi）
        $this->assertStringStartsWith('https://demo-fapi.binance.com/', $futUrl,
            '当 usd_m.testnet=true 时应该用其 testnet_url（没覆盖时用 MARKET_DEFAULTS 的 demo-fapi）。'
            . '注意：base_url 自定义为 custom-fapi 但 testnet=true 时仍优先 testnet_url');

        // --- 弹栈后对象属性还原回顶层值 ---
        $this->assertTrue($this->getProtectedProp($mock, 'sslVerify'), '弹栈后 sslVerify 必须还原回 SPOT=true');
        $this->assertFalse($this->getProtectedProp($mock, 'testnet'),  '弹栈后 testnet 必须还原回 SPOT=false');
    }

    // ==================================================================
    //  B. 6 个公共方法 × 3 市场 → path & query 正确
    // ==================================================================

    /**
     * 3 市场 × getTicker → 相对 path ticker/price；native symbol 由 BinanceSymbolFormatter 产出。
     *
     * @dataProvider provideTickerSymbolMatrix
     */
    public function testGetTickerBuildsCorrectQuery(string $symbol, string $expectedNative, string $marketLabel): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured);
        $t = $mock->getTicker($symbol);

        $this->assertCount(1, $captured, "{$marketLabel} ticker 应该只发 1 次请求");
        $c = $captured[0];
        $this->assertSame('GET', $c['method']);
        $this->assertSame('~ticker/price', $c['path'], "{$marketLabel}: path 须为相对前缀写法 ~ticker/price");
        $nativeActual = $c['query']['symbol'] ?? null;
        $expectedNativeIsRegex = is_string($expectedNative) && strlen($expectedNative) > 1
            && ($expectedNative[0] === '/') && (substr($expectedNative, -1) === '/');
        if ($expectedNativeIsRegex) {
            $this->assertMatchesRegularExpression($expectedNative, (string) $nativeActual,
                "{$marketLabel}: 传入 {$symbol} 格式化后的原生 symbol 不匹配正则");
        } else {
            $this->assertSame($expectedNative, $nativeActual,
                "{$marketLabel}: 传入 {$symbol} 格式化后的原生 symbol 不对");
        }
        $this->assertFalse($c['sign'], 'ticker 是公开接口，不能签名');
        // 标准化结果字段
        $this->assertSame($symbol, $t['symbol']);
        $this->assertIsFloat($t['price']);
        $this->assertGreaterThan(0, $t['timestamp']);
    }

    public function provideTickerSymbolMatrix(): array
    {
        return [
            // [标准符号, 期望的 Binance 原生 symbol（字符串或正则）, 描述标签]
            ['BTC/USDT',        'BTCUSDT',        'SPOT'],
            ['BTC/USDT:SWAP',   'BTCUSDT',        'USD-M SWAP（BinanceSymbolFormatter 原生永续没有后缀）'],
            ['BTC/USDT:FUT-250627', 'BTCUSDT_250627', 'USD-M FUTURES 日期后缀'],
            ['BTC/USD:SWAP',    'BTCUSD_PERP',    'COIN-M SWAP quote=USD → BTCUSD_PERP（dapi 格式）'],
            // BTC/USD:QUARTER → TradingSymbol 会把 QUARTER 规范化为具体交割日（YYMMDD），
            //   BinanceSymbolFormatter 再产出 BTCUSD_YYMMDD；精确日期随当前时间变化，用模式匹配
            ['BTC/USD:QUARTER', '/^BTCUSD_\d{6}$/', 'COIN-M QUARTER → TradingSymbol 规范化后 BinanceSymbolFormatter 产出 BTCUSD_YYMMDD'],
        ];
    }

    /**
     * getOrderBook：必须包含 limit=50，相对 path ~depth
     * @dataProvider provideThreeMarkets
     */
    public function testGetOrderBookIncludesLimitAndRelativePath(string $symbol, string $label): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured);
        $ob = $mock->getOrderBook($symbol, 50);
        $this->assertSame('~depth', $captured[0]['path'], "{$label}: path 必须是 ~depth");
        $this->assertSame(50, $captured[0]['query']['limit'] ?? null);
        $this->assertIsArray($ob['bids'] ?? null);
        $this->assertIsArray($ob['asks'] ?? null);
    }

    /**
     * getKlines 3 市场：都必须包含 startTime/endTime，limit 默认 100
     * @dataProvider provideThreeMarkets
     */
    public function testGetKlinesIncludesStartEndTime(string $symbol, string $label): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured);
        $rows = $mock->getKlines($symbol, '1h', 10, 1_700_000_000_000, 1_700_003_600_000);

        $this->assertSame('~klines', $captured[0]['path'], "{$label}: path 必须是 ~klines");
        $q = $captured[0]['query'];
        $this->assertSame(10,                          $q['limit'] ?? null);
        $this->assertSame('1h',                        $q['interval'] ?? null);
        $this->assertSame(1_700_000_000_000,           $q['startTime'] ?? null, "{$label}: startTime 必传");
        $this->assertSame(1_700_003_600_000,           $q['endTime'] ?? null,   "{$label}: endTime 必传");
        $this->assertFalse($captured[0]['sign']);
        $this->assertCount(2, $rows, 'normalizeKlines 应返回 2 行（FAKE_KLINES 的行数）');
    }

    /**
     * getTrades 3 市场：path ~trades，query 含 limit=100
     * @dataProvider provideThreeMarkets
     */
    public function testGetTradesPathAndLimit(string $symbol, string $label): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured);
        $mock->getTrades($symbol, 100);
        $this->assertSame('~trades', $captured[0]['path']);
        $this->assertSame(100, $captured[0]['query']['limit'] ?? null);
    }

    /**
     * getServerTime：固定走 SPOT 的 /time（不管传什么 market，context 内强制 SPOT）
     */
    public function testGetServerTimeUsesSpotTime(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured);
        $ts = $mock->getServerTime();
        $this->assertSame('~time', $captured[0]['path']);
        $this->assertSame(1700000000123, $ts, 'mock 返回 serverTime=1700000000123');

        // buildRequest 内部：context market=spot → URL 必须是 api.binance.com/api/v3/time
        $req = $this->invokeProtected($mock, 'withMarketContext',
            [BinanceExchange::MARKET_SPOT, function () use ($mock) {
                return $this->invokeProtected($mock, 'buildRequest', ['~time', 'GET', [], false]);
            }]);
        $this->assertSame('https://api.binance.com/api/v3/time', $req['url']);
    }

    public function provideThreeMarkets(): array
    {
        return [
            // [symbol, label]
            ['BTC/USDT',      'SPOT'],
            ['ETH/USDT:SWAP', 'USD-M'],
            ['BTC/USD:SWAP',  'COIN-M'],
        ];
    }

    // ==================================================================
    //  C. 签名接口：createOrder / cancelOrder / getOrder 字段 + HMAC
    // ==================================================================

    /**
     * createOrder(SPOT limit)：query 必须包含 symbol/side/type/quantity/price/timeInForce=GTC
     */
    public function testCreateOrderSpotLimitInjectsTimeInForce(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured, [
            'api_key' => 'k', 'secret' => 's',
        ]);

        $order = $mock->createOrder([
            'symbol' => 'BTC/USDT',
            'side'   => 'buy',
            'type'   => 'limit',
            'amount' => 0.1,
            'price'  => 40000,
        ]);

        $this->assertCount(1, $captured);
        $c = $captured[0];
        $this->assertSame('POST',       $c['method']);
        $this->assertSame('~order',     $c['path']);
        $this->assertTrue($c['sign'],   '下单必须签名');
        $q = $c['query'];
        $this->assertSame('BTCUSDT',    $q['symbol'] ?? null);
        $this->assertSame('BUY',        $q['side'] ?? null);
        $this->assertSame('LIMIT',      $q['type'] ?? null);
        $this->assertSame(0.1,          $q['quantity'] ?? null);
        $this->assertSame(40000.0,      $q['price'] ?? null);
        $this->assertSame('GTC',        $q['timeInForce'] ?? null, 'Spot LIMIT 单强制 GTC');
        $this->assertArrayNotHasKey('pair', $q, 'SPOT 不需要 pair 字段（USD-M 也不需要）');
    }

    /**
     * createOrder(USD-M SWAP market) 含 futures 扩展参数 → key 必须重命名为 Binance 风格：
     *   position_side→positionSide / reduce_only→reduceOnly / close_position→closePosition / leverage→leverage
     */
    public function testCreateOrderUsdMFuturesRenamesKeys(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured, [
            'api_key' => 'k', 'secret' => 's',
        ]);
        $mock->createOrder([
            'symbol'        => 'BTC/USDT:SWAP',
            'side'          => 'SELL',
            'type'          => 'MARKET',
            'amount'        => 0.05,
            'position_side' => 'SHORT',
            'reduce_only'   => true,
            'close_position'=> false,
            'leverage'      => 10,
        ]);
        $q = $captured[0]['query'];
        // 原生 symbol 是 BTCUSDT（USD-M 永续），不是 BTCUSDT_PERP
        $this->assertSame('BTCUSDT', $q['symbol'] ?? null);
        $this->assertSame('SHORT',   $q['positionSide'] ?? null,  'position_side → positionSide 重命名失败');
        $this->assertTrue($q['reduceOnly'] ?? null,               'reduce_only → reduceOnly 重命名失败');
        $this->assertFalse($q['closePosition'] ?? null,           'close_position → closePosition 重命名失败');
        $this->assertSame(10,        $q['leverage'] ?? null,      'leverage 原样传递');
        $this->assertArrayNotHasKey('position_side', $q, 'snake_case 源 key 不能保留（应被重命名）');
        $this->assertArrayNotHasKey('pair',          $q, 'USD-M 不需要 pair 字段（只有 COIN-M 要）');
    }

    /**
     * createOrder(COIN-M) 必须额外注入 pair=BTCUSD；原生 symbol=BTCUSD_PERP
     */
    public function testCreateOrderCoinMAutoInjectsPair(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured, [
            'api_key' => 'k', 'secret' => 's',
        ]);
        $mock->createOrder([
            'symbol' => 'BTC/USD:SWAP',
            'side'   => 'BUY',
            'type'   => 'LIMIT',
            'amount' => 1,       // COIN-M 这里是「张」
            'price'  => 42000,
        ]);
        $q = $captured[0]['query'];
        $this->assertSame('BTCUSD_PERP', $q['symbol'] ?? null, 'COIN-M 永续原生 symbol 是 BTCUSD_PERP');
        $this->assertSame('BTCUSD',      $q['pair']   ?? null, 'COIN-M 订单必须额外带 pair=BASEQUOTE');
        $this->assertSame('GTC',         $q['timeInForce'] ?? null);
    }

    /**
     * HMAC + X-MBX-APIKEY：签名请求 buildRequest 输出必须带 signature=xxx，且 URL query 有 timestamp。
     */
    public function testBuildRequestSignedIncludesHmacAndKeyHeader(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured, [
            'api_key' => 'THEKEY',
            'secret'  => 'THESECRET',
        ]);

        // 注意：signed=true 会调用 $this->getServerTimestampMs() → 内部走 getServerTime() →
        //   会多触发一次 request('~time', ...) 调用，然后用 clock delta。
        //   为了避免 HMAC 输入中 timestamp 每次变化，用反射 setCachedDelta=0（static 不行）→ 直接手动构造场景：
        //   不在 context 里调，而是手动调 buildRequest → timestamp 会真实跑 getServerTime
        // 这里的方案：接受 timestamp 字段出现 + signature 是 64 hex 字符 + header 有 key。
        $req = $this->invokeProtected($mock, 'withMarketContext',
            ['BTC/USDT', function () use ($mock) {
                // 取消 sign 的 request() 调用前，先「暖 getServerTime」一次 → 填充 cachedDelta
                //   (通过 invoke getServerTimestampMs 也行)
                $this->invokeProtected($mock, 'getServerTimestampMs', []);
                // 然后 buildRequest 再取一次时间（因为 cachedDelta 已经缓存，时间不会变）
                $ts1 = $this->invokeProtected($mock, 'getServerTimestampMs', []);
                $req = $this->invokeProtected($mock, 'buildRequest',
                    ['~order', 'POST', ['symbol'=>'BTCUSDT','side'=>'BUY','type'=>'MARKET','quantity'=>0.1], true]);
                return [$ts1, $req];
            }]);
        [$ts1, $req] = $req;

        // URL 结构：{base}{prefix}/order?k=v...&timestamp={ts}&recvWindow=5000&signature={64-char-hex}
        $this->assertSame('https://api.binance.com/api/v3/order', explode('?', $req['url'])[0]);
        $this->assertMatchesRegularExpression('/&timestamp=\d+/',         $req['url'], 'signed 请求必须带 timestamp');
        $this->assertMatchesRegularExpression('/&recvWindow=5000/',       $req['url'], 'signed 请求默认带 recvWindow=5000');
        $this->assertMatchesRegularExpression('/&signature=[0-9a-f]{64}$/',$req['url'],'signature 必须是 64 hex 尾缀');
        $this->assertSame('THEKEY', $req['headers']['X-MBX-APIKEY'] ?? null, 'header 必带 X-MBX-APIKEY');

        // HMAC 正确性：手动把 query string（去掉 signature= 后缀）用 THESECRET 算 sha256 → 应匹配签名值
        if (preg_match('/\?(?<query>.+)&signature=(?<sig>[0-9a-f]{64})$/', $req['url'], $m)) {
            $computed = hash_hmac('sha256', $m['query'], 'THESECRET');
            $this->assertSame($m['sig'], $computed, 'HMAC 签名不正确：Binance HMAC_SHA256(queryString, secret)');
        } else {
            $this->fail('signed URL 无法解析为 query + signature 两段');
        }
    }

    /**
     * cancelOrder / getOrder 对 COIN-M 必须自动注入 pair
     */
    public function testCancelAndGetOrderCoinMIncludePair(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured, ['api_key'=>'k','secret'=>'s']);

        $mock->cancelOrder('999', 'BTC/USD:QUARTER');
        $mock->getOrder('999',    'BTC/USD:QUARTER');

        $this->assertCount(2, $captured);
        [$cancelC, $getC] = $captured;

        // cancel
        $this->assertSame('DELETE',    $cancelC['method']);
        $this->assertSame('~order',    $cancelC['path']);
        $this->assertSame('999',       $cancelC['query']['orderId'] ?? null);
        $this->assertSame('BTCUSD',    $cancelC['query']['pair'] ?? null, 'cancelOrder COIN-M 须注入 pair');

        // get
        $this->assertSame('GET',       $getC['method']);
        $this->assertSame('~order',    $getC['path']);
        $this->assertSame('BTCUSD',    $getC['query']['pair'] ?? null, 'getOrder COIN-M 须注入 pair');
    }

    // ==================================================================
    //  D. Normalizer 字段映射（Spot vs Futures 各种形状都接受）
    // ==================================================================

    public function testNormalizeBalanceSpotShape(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured);
        // Spot 响应：顶层 balances 数组
        $res = $this->invokeProtected($mock, 'normalizeBalance', [[
            'balances' => [
                ['asset' => 'USDT', 'free' => '100.5', 'locked' => '0.5'],
                ['asset' => 'BNB',  'free' => '0',     'locked' => '0'],  // 全 0 → 过滤掉
            ],
        ]]);
        $this->assertArrayHasKey('USDT', $res);
        $this->assertSame(['free'=>100.5,'used'=>0.5,'total'=>101.0], $res['USDT']);
        $this->assertArrayNotHasKey('BNB', $res, 'free=used=0 的资产必须过滤');
    }

    public function testNormalizeBalanceFuturesListShape(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured);
        // USD-M /fapi/v2/balance 形状：数字索引 list → {asset, balance, availableBalance, crossWalletBalance}
        $res = $this->invokeProtected($mock, 'normalizeBalance', [[
            ['accountAlias' => 'abc', 'asset' => 'USDT',
             'balance' => '120', 'availableBalance' => '100', 'crossWalletBalance' => '120'],
            ['asset' => 'BNB',  'balance' => '0',   'availableBalance' => '0'],
            // COIN-M 常见：availableBalance 键换成 withdrawAvailable
            ['asset' => 'USD',  'balance' => '50',  'withdrawAvailable' => '40'],
        ]]);

        $this->assertSame(['free'=>100.0,'used'=>20.0,'total'=>120.0], $res['USDT'],
            'Futures: total=crossWalletBalance, used=total-availableBalance');
        $this->assertArrayNotHasKey('BNB', $res, '全 0 资产过滤');
        $this->assertSame(40.0, $res['USD']['free'],       'COIN-M availableBalance 别名 withdrawAvailable 必须接受');
        $this->assertSame(10.0, $res['USD']['used'],       'used = total(50) - free(40)');
    }

    public function testNormalizeOrderFuturesExtensionFields(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured);
        $raw = [
            'orderId' => '42', 'clientOrderId' => 'abc', 'symbol' => 'BTCUSDT',
            'pair'    => 'BTCUSD',    // COIN-M 才会带
            'status'  => 'FILLED', 'type' => 'LIMIT', 'side' => 'BUY',
            'price'   => '42000', 'origQty' => '0.5', 'executedQty' => '0.5',
            'avgPrice' => '42012.3',  // Futures 专属 → 映射到 avg_fill_price
            'reduceOnly'   => true,   // Futures 专属
            'positionSide' => 'LONG', // Futures 专属
            'updateTime'   => 1700000000999,
        ];
        $o = $this->invokeProtected($mock, 'normalizeOrder', [$raw]);
        $this->assertSame('42',              $o['id']);
        $this->assertSame('BTCUSD',          $o['pair'],           'COIN-M pair 字段必须透传');
        $this->assertSame(42012.3,           $o['avg_fill_price'], 'Futures avgPrice → avg_fill_price');
        $this->assertTrue($o['reduce_only'],                       'Futures reduceOnly → reduce_only');
        $this->assertSame('LONG',          $o['position_side'],   'Futures positionSide → position_side');
        $this->assertSame(1700000000999,   $o['timestamp'],       '优先用 updateTime');
        $this->assertSame(0.0,             $o['remaining'],       'remaining = origQty - executedQty = 0.5 - 0.5 = 0');
    }

    public function testNormalizeTickerAcceptsLastPriceAndTimeFallbacks(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured);
        // 典型 COIN-M 24hr ticker：字段叫 lastPrice / T / E
        $t1 = $this->invokeProtected($mock, 'normalizeTicker', [[
            'symbol' => 'BTCUSD_PERP', 'lastPrice' => '43000',
            'T' => 1700000000001, 'E' => 1700000000002,
        ], 'BTC/USD:SWAP']);
        $this->assertSame(43000.0,           $t1['price']);
        $this->assertSame(1700000000001,     $t1['timestamp'], '时间字段优先级：time > T > E');

        // 一般 Spot ticker/price：字段 price / time
        $t2 = $this->invokeProtected($mock, 'normalizeTicker', [[
            'symbol' => 'BTCUSDT', 'price' => '44000', 'time' => 1700000000003,
        ], 'BTC/USDT']);
        $this->assertSame(44000.0,           $t2['price']);
        $this->assertSame(1700000000003,     $t2['timestamp']);
    }

    // ==================================================================
    //  E. 错误处理：checkApiError 接受 0，拒绝非 0（正/负 code 都抛）
    // ==================================================================

    public function testCheckApiErrorZeroCodeDoesNotThrow(): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured);
        try {
            $this->invokeProtected($mock, 'checkApiError', [['code' => 0, 'msg' => ''], 'dummy-url']);
            $this->assertTrue(true, 'code=0 不应抛异常');
        } catch (ExchangeException $e) {
            $this->fail('code=0 被 checkApiError 误判为错误：' . $e->getMessage());
        }
    }

    /**
     * @dataProvider provideErrorCodes
     */
    public function testCheckApiErrorNonZeroCodeThrows($code, string $msg): void
    {
        $captured = [];
        /** @var BinanceExchange $mock */
        $mock = $this->makeBinanceMock($captured);
        $this->expectException(ExchangeException::class);
        $this->invokeProtected($mock, 'checkApiError', [['code' => $code, 'msg' => $msg], 'url']);
    }
    public function provideErrorCodes(): array
    {
        return [
            [-11021, 'Invalid API-key'],   // Spot 常见负 code
            [-2015,  'Invalid API-key, IP, or permissions for action.'],
            [400,    'Bad Request'],       // Futures 常见正 code（HTTP 400 级别业务错）
            [-1021,  'Timestamp outside recvWindow'],
        ];
    }
}
