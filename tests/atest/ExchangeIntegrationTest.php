<?php

namespace Sikelan\Tests\Atest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use App\Services\Exchanges\Adapters\BinanceExchange;
use App\Services\Exchanges\Adapters\OkxExchange;
use App\Services\Exchanges\ExchangeManager;

/**
 * 交易所服务集成测试
 *
 * 使用 Swoole 协程实际请求 Binance 和 OKX 的公开 API，
 * 验证适配器在真实环境下的端到端工作流程。
 *
 * 注意：
 * - 需要 Swoole 扩展
 * - 不能在 Xdebug 环境下运行（与协程冲突）
 * - 只测试公开接口（无需 API Key）
 * - 需要网络连接
 */
class ExchangeIntegrationTest extends TestCase
{
    /**
     * 检查是否可以运行 Swoole 协程测试
     */
    protected function canRunSwooleTest(): bool
    {
        // 检查 Swoole 扩展
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension not available');
            return false;
        }

        // 检查 Xdebug（与协程冲突）
        if (extension_loaded('xdebug')) {
            $this->markTestSkipped('Xdebug conflicts with Swoole coroutines. '
                . 'Run with: php -c /tmp/php_no_xdebug.ini vendor/bin/phpunit');
            return false;
        }

        // 检查网络连通性（尝试连接 Binance API）
        $ch = curl_init('https://api.binance.com/api/v3/time');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 0) {
            $this->markTestSkipped('Network unavailable - cannot reach exchange APIs');
            return false;
        }

        return true;
    }

    /**
     * 在 Swoole 协程中运行测试
     *
     * 使用 Coroutine::run() 创建协程并阻塞等待完成，
     * 捕获协程内的异常并重新抛出，确保 PHPUnit 能正确报告断言失败
     */
    protected function runInCoroutine(callable $callback): void
    {
        $exception = null;

        \Swoole\Coroutine\run(function () use ($callback, &$exception) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $exception = $e;
            }
        });

        // 将协程内的异常重新抛出给 PHPUnit
        if ($exception !== null) {
            throw $exception;
        }
    }

    private function createBinanceExchange(): BinanceExchange
    {
        $config = new Config();
        $config->set('exchanges.binance', [
            'base_url' => 'https://api.binance.com',
            'api_key' => 'test_key',
            'secret' => 'test_secret',
            'rate_limit_ms' => 0, // 测试中不限制速率
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
            'rate_limit_ms' => 0,
        ]);
        return new OkxExchange($config, new Logger($config));
    }

    // ========== Binance 公开接口测试 ==========

    public function testBinanceGetServerTime()
    {
        if (!$this->canRunSwooleTest()) {
            return;
        }

        $this->runInCoroutine(function () {
            $exchange = $this->createBinanceExchange();
            $time = $exchange->getServerTime();

            $this->assertGreaterThan(0, $time, 'Binance server time should be positive');

            // 服务器时间应接近当前毫秒时间戳（容差 60 秒）
            $now = (int) (microtime(true) * 1000);
            $this->assertLessThan(60000, abs($now - $time), 'Binance time drift should be < 60s');
        });
    }

    public function testBinanceGetTicker()
    {
        if (!$this->canRunSwooleTest()) {
            return;
        }

        $this->runInCoroutine(function () {
            $exchange = $this->createBinanceExchange();
            $ticker = $exchange->getTicker('BTC/USDT');

            $this->assertEquals('BTC/USDT', $ticker['symbol']);
            $this->assertGreaterThan(0, $ticker['price'], 'BTC price should be positive');
            $this->assertGreaterThan(0, $ticker['timestamp'], 'Timestamp should be positive');
        });
    }

    public function testBinanceGetOrderBook()
    {
        if (!$this->canRunSwooleTest()) {
            return;
        }

        $this->runInCoroutine(function () {
            $exchange = $this->createBinanceExchange();
            $book = $exchange->getOrderBook('BTC/USDT', 5);

            $this->assertArrayHasKey('bids', $book);
            $this->assertArrayHasKey('asks', $book);
            $this->assertNotEmpty($book['bids'], 'Bids should not be empty');
            $this->assertNotEmpty($book['asks'], 'Asks should not be empty');

            // 买一价应低于卖一价
            $bidPrice = $book['bids'][0][0];
            $askPrice = $book['asks'][0][0];
            $this->assertLessThan($askPrice, $bidPrice, 'Bid price should be less than ask price');
        });
    }

    public function testBinanceGetKlines()
    {
        if (!$this->canRunSwooleTest()) {
            return;
        }

        $this->runInCoroutine(function () {
            $exchange = $this->createBinanceExchange();
            $klines = $exchange->getKlines('BTC/USDT', '1m', 5);

            $this->assertCount(5, $klines, 'Should return 5 klines');

            // 验证每条 K 线格式：[timestamp, open, high, low, close, volume]
            $first = $klines[0];
            $this->assertCount(6, $first);
            $this->assertGreaterThan(0, $first[0], 'Timestamp should be positive');
            $this->assertGreaterThan(0, $first[4], 'Close price should be positive');
            $this->assertGreaterThan(0, $first[5], 'Volume should be positive');

            // high >= max(open, close), low <= min(open, close)
            $this->assertGreaterThanOrEqual($first[1], $first[2], 'High >= Open');
            $this->assertGreaterThanOrEqual($first[4], $first[2], 'High >= Close');
            $this->assertLessThanOrEqual($first[1], $first[3], 'Low <= Open');
            $this->assertLessThanOrEqual($first[4], $first[3], 'Low <= Close');
        });
    }

    public function testBinanceGetTrades()
    {
        if (!$this->canRunSwooleTest()) {
            return;
        }

        $this->runInCoroutine(function () {
            $exchange = $this->createBinanceExchange();
            $trades = $exchange->getTrades('BTC/USDT', 5);

            $this->assertNotEmpty($trades, 'Trades should not be empty');

            $first = $trades[0];
            $this->assertGreaterThan(0, $first['price']);
            $this->assertGreaterThan(0, $first['qty']);
            $this->assertContains($first['side'], ['buy', 'sell']);
        });
    }

    // ========== OKX 公开接口测试 ==========

    public function testOkxGetServerTime()
    {
        if (!$this->canRunSwooleTest()) {
            return;
        }

        $this->runInCoroutine(function () {
            $exchange = $this->createOkxExchange();
            $time = $exchange->getServerTime();

            $this->assertGreaterThan(0, $time, 'OKX server time should be positive');

            $now = (int) (microtime(true) * 1000);
            $this->assertLessThan(60000, abs($now - $time), 'OKX time drift should be < 60s');
        });
    }

    public function testOkxGetTicker()
    {
        if (!$this->canRunSwooleTest()) {
            return;
        }

        $this->runInCoroutine(function () {
            $exchange = $this->createOkxExchange();
            $ticker = $exchange->getTicker('BTC/USDT');

            $this->assertEquals('BTC/USDT', $ticker['symbol']);
            $this->assertGreaterThan(0, $ticker['price'], 'BTC price should be positive');
            $this->assertGreaterThan(0, $ticker['timestamp'], 'Timestamp should be positive');
        });
    }

    public function testOkxGetOrderBook()
    {
        if (!$this->canRunSwooleTest()) {
            return;
        }

        $this->runInCoroutine(function () {
            $exchange = $this->createOkxExchange();
            $book = $exchange->getOrderBook('BTC/USDT', 5);

            $this->assertArrayHasKey('bids', $book);
            $this->assertArrayHasKey('asks', $book);
            $this->assertNotEmpty($book['bids']);
            $this->assertNotEmpty($book['asks']);

            $bidPrice = $book['bids'][0][0];
            $askPrice = $book['asks'][0][0];
            $this->assertLessThan($askPrice, $bidPrice, 'Bid < Ask');
        });
    }

    public function testOkxGetKlines()
    {
        if (!$this->canRunSwooleTest()) {
            return;
        }

        $this->runInCoroutine(function () {
            $exchange = $this->createOkxExchange();
            $klines = $exchange->getKlines('BTC/USDT', '1m', 5);

            $this->assertGreaterThanOrEqual(1, count($klines));

            $first = $klines[0];
            $this->assertCount(6, $first);
            $this->assertGreaterThan(0, $first[0]);
        });
    }

    // ========== 跨交易所一致性测试 ==========

    public function testCrossExchangePriceConsistency()
    {
        if (!$this->canRunSwooleTest()) {
            return;
        }

        $this->runInCoroutine(function () {
            $binance = $this->createBinanceExchange();
            $okx = $this->createOkxExchange();

            $binanceTicker = $binance->getTicker('BTC/USDT');
            $okxTicker = $okx->getTicker('BTC/USDT');

            // 两个交易所的 BTC 价格应在 5% 范围内一致
            $binancePrice = $binanceTicker['price'];
            $okxPrice = $okxTicker['price'];
            $diff = abs($binancePrice - $okxPrice) / $binancePrice;

            $this->assertLessThan(0.05, $diff, "BTC price difference should be < 5% "
                . "(Binance: {$binancePrice}, OKX: {$okxPrice}, diff: " . round($diff * 100, 2) . "%)");
        });
    }

    // ========== ExchangeManager 集成测试 ==========

    public function testManagerWithRealAdapters()
    {
        if (!$this->canRunSwooleTest()) {
            return;
        }

        $this->runInCoroutine(function () {
            $config = new Config();
            $config->set('exchanges', [
                'default' => 'binance',
                'binance' => [
                    'base_url' => 'https://api.binance.com',
                    'api_key' => 'test_key',
                    'secret' => 'test_secret',
                    'rate_limit_ms' => 0,
                ],
                'okx' => [
                    'base_url' => 'https://www.okx.com',
                    'api_key' => 'test_key',
                    'secret' => 'test_secret',
                    'passphrase' => 'test_pass',
                    'rate_limit_ms' => 0,
                ],
            ]);

            $manager = new ExchangeManager($config, new Logger($config));

            // 通过管理器调用默认交易所
            $ticker = $manager->getTicker('BTC/USDT');
            $this->assertEquals('BTC/USDT', $ticker['symbol']);
            $this->assertGreaterThan(0, $ticker['price']);

            // 通过管理器指定 OKX
            $okxTicker = $manager->exchange('okx')->getTicker('BTC/USDT');
            $this->assertGreaterThan(0, $okxTicker['price']);

            // 验证懒加载缓存（同一实例）
            $ex1 = $manager->exchange('binance');
            $ex2 = $manager->exchange('binance');
            $this->assertSame($ex1, $ex2);
        });
    }
}
