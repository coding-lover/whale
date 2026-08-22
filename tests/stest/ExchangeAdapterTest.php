<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use App\Services\Exchanges\Adapters\BinanceExchange;
use App\Services\Exchanges\Adapters\OkxExchange;

/**
 * 交易所适配器数据标准化测试
 *
 * 使用模拟数据测试两个适配器的 normalize 系列方法，
 * 验证不同交易所的原始响应能正确转换为统一格式。
 * 不依赖 Swoole 和网络请求。
 */
class ExchangeAdapterTest extends TestCase
{
    private function createBinanceExchange(): BinanceExchange
    {
        $config = new Config();
        $config->set('exchanges.binance', [
            'base_url' => 'https://api.binance.com',
            'api_key' => 'test_key',
            'secret' => 'test_secret',
        ]);
        return new BinanceExchange($config, new Logger($config));
    }

    private function createOkxExchange(): OkxExchange
    {
        $config = new Config();
        $config->set('exchanges.okx', [
            'base_url' => 'https://www.okx.com',
            'api_key' => 'test_key',
            'secret' => 'test_secret',
            'passphrase' => 'test_pass',
        ]);
        return new OkxExchange($config, new Logger($config));
    }

    // ========== Binance 标准化测试 ==========

    public function testBinanceNormalizeTicker()
    {
        $exchange = $this->createBinanceExchange();

        // 通过反射调用 protected 方法
        $method = new \ReflectionMethod($exchange, 'normalizeTicker');
        $method->setAccessible(true);

        $raw = ['symbol' => 'BTCUSDT', 'price' => '50000.00', 'time' => 1234567890000];
        $result = $method->invoke($exchange, $raw, 'BTC/USDT');

        $this->assertEquals('BTC/USDT', $result['symbol']);
        $this->assertEquals(50000.00, $result['price']);
        $this->assertEquals(1234567890000, $result['timestamp']);
    }

    public function testBinanceNormalizeOrderBook()
    {
        $exchange = $this->createBinanceExchange();

        $method = new \ReflectionMethod($exchange, 'normalizeOrderBook');
        $method->setAccessible(true);

        $raw = [
            'bids' => [['50000.00', '1.5'], ['49999.00', '2.0']],
            'asks' => [['50001.00', '0.8'], ['50002.00', '3.0']],
        ];
        $result = $method->invoke($exchange, $raw);

        $this->assertCount(2, $result['bids']);
        $this->assertCount(2, $result['asks']);
        $this->assertEquals(50000.00, $result['bids'][0][0]);
        $this->assertEquals(1.5, $result['bids'][0][1]);
    }

    public function testBinanceNormalizeKlines()
    {
        $exchange = $this->createBinanceExchange();

        $method = new \ReflectionMethod($exchange, 'normalizeKlines');
        $method->setAccessible(true);

        $raw = [
            [1234567890000, '50000', '50100', '49900', '50050', '1.5'],
            [1234567950000, '50050', '50200', '50000', '50150', '2.0'],
        ];
        $result = $method->invoke($exchange, $raw);

        $this->assertCount(2, $result);
        $this->assertEquals(1234567890000, $result[0][0]);
        $this->assertEquals(50000.0, $result[0][1]);
        $this->assertEquals(1.5, $result[0][5]);
    }

    public function testBinanceNormalizeBalance()
    {
        $exchange = $this->createBinanceExchange();

        $method = new \ReflectionMethod($exchange, 'normalizeBalance');
        $method->setAccessible(true);

        $raw = [
            'balances' => [
                ['asset' => 'BTC', 'free' => '1.0', 'locked' => '0.5'],
                ['asset' => 'ETH', 'free' => '0.0', 'locked' => '0.0'],
                ['asset' => 'USDT', 'free' => '10000', 'locked' => '0'],
            ],
        ];
        $result = $method->invoke($exchange, $raw);

        // ETH 余额为 0，应被过滤
        $this->assertArrayHasKey('BTC', $result);
        $this->assertArrayHasKey('USDT', $result);
        $this->assertArrayNotHasKey('ETH', $result);

        $this->assertEquals(1.0, $result['BTC']['free']);
        $this->assertEquals(0.5, $result['BTC']['used']);
        $this->assertEquals(1.5, $result['BTC']['total']);
    }

    public function testBinanceNormalizeOrder()
    {
        $exchange = $this->createBinanceExchange();

        $method = new \ReflectionMethod($exchange, 'normalizeOrder');
        $method->setAccessible(true);

        $raw = [
            'orderId' => '123456',
            'clientOrderId' => 'my_order_1',
            'symbol' => 'BTCUSDT',
            'status' => 'NEW',
            'type' => 'LIMIT',
            'side' => 'BUY',
            'price' => '50000',
            'origQty' => '0.001',
            'executedQty' => '0.0005',
            'transactTime' => 1234567890000,
        ];
        $result = $method->invoke($exchange, $raw);

        $this->assertEquals('123456', $result['id']);
        $this->assertEquals('my_order_1', $result['clientOrderId']);
        $this->assertEquals('new', $result['status']);
        $this->assertEquals('limit', $result['type']);
        $this->assertEquals('buy', $result['side']);
        $this->assertEquals(50000.0, $result['price']);
        $this->assertEquals(0.001, $result['amount']);
        $this->assertEquals(0.0005, $result['filled']);
        $this->assertEquals(0.0005, $result['remaining']);
    }

    public function testBinanceFormatSymbol()
    {
        $exchange = $this->createBinanceExchange();

        $method = new \ReflectionMethod($exchange, 'formatSymbol');
        $method->setAccessible(true);

        $this->assertEquals('BTCUSDT', $method->invoke($exchange, 'BTC/USDT'));
        $this->assertEquals('ETHBTC', $method->invoke($exchange, 'eth/btc'));
    }

    // ========== OKX 标准化测试 ==========

    public function testOkxNormalizeTicker()
    {
        $exchange = $this->createOkxExchange();

        $method = new \ReflectionMethod($exchange, 'normalizeTicker');
        $method->setAccessible(true);

        $raw = [
            'code' => '0',
            'data' => [[
                'instId' => 'BTC-USDT',
                'last' => '50000.00',
                'ts' => '1234567890000',
            ]],
        ];
        $result = $method->invoke($exchange, $raw, 'BTC/USDT');

        $this->assertEquals('BTC/USDT', $result['symbol']);
        $this->assertEquals(50000.00, $result['price']);
        $this->assertEquals(1234567890000, $result['timestamp']);
    }

    public function testOkxNormalizeOrderBook()
    {
        $exchange = $this->createOkxExchange();

        $method = new \ReflectionMethod($exchange, 'normalizeOrderBook');
        $method->setAccessible(true);

        $raw = [
            'code' => '0',
            'data' => [[
                'bids' => [['50000.00', '1.5', '0', '1'], ['49999.00', '2.0', '0', '1']],
                'asks' => [['50001.00', '0.8', '0', '1'], ['50002.00', '3.0', '0', '1']],
            ]],
        ];
        $result = $method->invoke($exchange, $raw);

        $this->assertCount(2, $result['bids']);
        $this->assertCount(2, $result['asks']);
        $this->assertEquals(50000.00, $result['bids'][0][0]);
        $this->assertEquals(1.5, $result['bids'][0][1]);
    }

    public function testOkxNormalizeKlines()
    {
        $exchange = $this->createOkxExchange();

        $method = new \ReflectionMethod($exchange, 'normalizeKlines');
        $method->setAccessible(true);

        $raw = [
            'code' => '0',
            'data' => [
                ['1234567890000', '50000', '50100', '49900', '50050', '1.5'],
                ['1234567950000', '50050', '50200', '50000', '50150', '2.0'],
            ],
        ];
        $result = $method->invoke($exchange, $raw);

        $this->assertCount(2, $result);
        $this->assertEquals(1234567890000, $result[0][0]);
        $this->assertEquals(50000.0, $result[0][1]);
    }

    public function testOkxNormalizeBalance()
    {
        $exchange = $this->createOkxExchange();

        $method = new \ReflectionMethod($exchange, 'normalizeBalance');
        $method->setAccessible(true);

        $raw = [
            'code' => '0',
            'data' => [
                ['ccy' => 'BTC', 'availBal' => '1.0', 'frozenBal' => '0.5'],
                ['ccy' => 'ETH', 'availBal' => '0.0', 'frozenBal' => '0.0'],
                ['ccy' => 'USDT', 'availBal' => '10000', 'frozenBal' => '0'],
            ],
        ];
        $result = $method->invoke($exchange, $raw);

        $this->assertArrayHasKey('BTC', $result);
        $this->assertArrayHasKey('USDT', $result);
        $this->assertArrayNotHasKey('ETH', $result);

        $this->assertEquals(1.0, $result['BTC']['free']);
        $this->assertEquals(0.5, $result['BTC']['used']);
        $this->assertEquals(1.5, $result['BTC']['total']);
    }

    public function testOkxNormalizeOrder()
    {
        $exchange = $this->createOkxExchange();

        $method = new \ReflectionMethod($exchange, 'normalizeOrder');
        $method->setAccessible(true);

        $raw = [
            'code' => '0',
            'data' => [[
                'ordId' => '123456',
                'clOrdId' => 'my_order_1',
                'instId' => 'BTC-USDT',
                'state' => 'live',
                'ordType' => 'limit',
                'side' => 'buy',
                'px' => '50000',
                'sz' => '0.001',
                'accFillSz' => '0.0005',
                'uTime' => '1234567890000',
            ]],
        ];
        $result = $method->invoke($exchange, $raw);

        $this->assertEquals('123456', $result['id']);
        $this->assertEquals('my_order_1', $result['clientOrderId']);
        $this->assertEquals('open', $result['status']);
        $this->assertEquals('limit', $result['type']);
        $this->assertEquals('buy', $result['side']);
        $this->assertEquals(50000.0, $result['price']);
        $this->assertEquals(0.001, $result['amount']);
        $this->assertEquals(0.0005, $result['filled']);
    }

    public function testOkxFormatSymbol()
    {
        $exchange = $this->createOkxExchange();

        $method = new \ReflectionMethod($exchange, 'formatSymbol');
        $method->setAccessible(true);

        $this->assertEquals('BTC-USDT', $method->invoke($exchange, 'BTC/USDT'));
        $this->assertEquals('ETH-BTC', $method->invoke($exchange, 'eth/btc'));
    }

    public function testOkxFormatInterval()
    {
        $exchange = $this->createOkxExchange();

        $method = new \ReflectionMethod($exchange, 'formatInterval');
        $method->setAccessible(true);

        // 小时及以上应转为大写
        $this->assertEquals('1m', $method->invoke($exchange, '1m'));
        $this->assertEquals('1H', $method->invoke($exchange, '1h'));
        $this->assertEquals('4H', $method->invoke($exchange, '4h'));
        $this->assertEquals('1D', $method->invoke($exchange, '1d'));
        $this->assertEquals('1W', $method->invoke($exchange, '1w'));
    }

    // ========== 签名验证 ==========

    public function testBinanceBuildSignedRequest()
    {
        $exchange = $this->createBinanceExchange();

        $method = new \ReflectionMethod($exchange, 'buildRequest');
        $method->setAccessible(true);

        $result = $method->invoke($exchange, '/api/v3/order', 'POST', [
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
        ], true);

        // 验证 URL 包含签名
        $this->assertStringContainsString('signature=', $result['url']);
        $this->assertStringContainsString('timestamp=', $result['url']);
        $this->assertArrayHasKey('X-MBX-APIKEY', $result['headers']);
    }

    public function testOkxBuildSignedRequest()
    {
        $exchange = $this->createOkxExchange();

        $method = new \ReflectionMethod($exchange, 'buildRequest');
        $method->setAccessible(true);

        $result = $method->invoke($exchange, '/api/v5/trade/order', 'POST', [
            'instId' => 'BTC-USDT',
            'tdMode' => 'cash',
            'side' => 'buy',
            'ordType' => 'limit',
            'sz' => '0.001',
            'px' => '50000',
        ], true);

        // 验证四个认证头
        $this->assertArrayHasKey('OK-ACCESS-KEY', $result['headers']);
        $this->assertArrayHasKey('OK-ACCESS-SIGN', $result['headers']);
        $this->assertArrayHasKey('OK-ACCESS-TIMESTAMP', $result['headers']);
        $this->assertArrayHasKey('OK-ACCESS-PASSPHRASE', $result['headers']);

        // 验证 body 是 JSON 格式
        $this->assertJson($result['body']);
    }
}
