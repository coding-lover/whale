<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use App\Services\Exchanges\ExchangeManager;
use App\Services\Exchanges\ExchangeException;
use App\Services\Exchanges\ExchangeInterface;
use App\Services\Exchanges\Adapters\BinanceExchange;
use App\Services\Exchanges\Adapters\OkxExchange;

/**
 * 交易所服务管理器单元测试
 *
 * 测试 ExchangeManager 的注册、实例化、默认交易所检测和错误处理
 * 不依赖 Swoole 和网络请求
 */
class ExchangeManagerTest extends TestCase
{
    /**
     * 创建带测试配置的 Config 实例
     */
    private function createConfig(array $exchangesConfig = []): Config
    {
        $config = new Config();

        // 默认配置：两个交易所都未配置 API Key
        $config->set('exchanges', array_merge([
            'default' => 'binance',
            'binance' => [
                'base_url' => 'https://api.binance.com',
                'api_key' => '',
                'secret' => '',
            ],
            'okx' => [
                'base_url' => 'https://www.okx.com',
                'api_key' => '',
                'secret' => '',
                'passphrase' => '',
            ],
        ], $exchangesConfig));

        return $config;
    }

    private function createLogger(Config $config): Logger
    {
        return new Logger($config);
    }

    // ========== 构造和注册测试 ==========

    public function testConstructorLoadsBuiltinAdapters()
    {
        $config = $this->createConfig();
        $logger = $this->createLogger($config);
        $manager = new ExchangeManager($config, $logger);

        $this->assertContains('binance', $manager->getRegisteredExchanges());
        $this->assertContains('okx', $manager->getRegisteredExchanges());
    }

    public function testConstructorLoadsCustomAdapters()
    {
        $config = $this->createConfig([
            'custom_adapters' => [
                'mock' => MockExchange::class,
            ],
        ]);
        $logger = $this->createLogger($config);
        $manager = new ExchangeManager($config, $logger);

        $this->assertContains('mock', $manager->getRegisteredExchanges());
    }

    // ========== 默认交易所测试 ==========

    public function testDefaultExchangeFromConfig()
    {
        $config = $this->createConfig([
            'default' => 'okx',
            'okx' => [
                'base_url' => 'https://www.okx.com',
                'api_key' => 'test_key',
                'secret' => 'test_secret',
                'passphrase' => 'test_pass',
            ],
        ]);
        $logger = $this->createLogger($config);
        $manager = new ExchangeManager($config, $logger);

        $this->assertEquals('okx', $manager->getDefaultExchangeName());
    }

    public function testDefaultExchangeAutoDetect()
    {
        // 不设置 default，但 okx 配置了 API Key
        $config = $this->createConfig([
            'default' => '',
            'okx' => [
                'base_url' => 'https://www.okx.com',
                'api_key' => 'test_key',
                'secret' => 'test_secret',
                'passphrase' => 'test_pass',
            ],
        ]);
        $logger = $this->createLogger($config);
        $manager = new ExchangeManager($config, $logger);

        $this->assertEquals('okx', $manager->getDefaultExchangeName());
    }

    // ========== 实例化测试 ==========

    public function testExchangeReturnsBinanceInstance()
    {
        $config = $this->createConfig([
            'binance' => [
                'base_url' => 'https://api.binance.com',
                'api_key' => 'test_key',
                'secret' => 'test_secret',
            ],
        ]);
        $logger = $this->createLogger($config);
        $manager = new ExchangeManager($config, $logger);

        $exchange = $manager->exchange('binance');
        $this->assertInstanceOf(BinanceExchange::class, $exchange);
        $this->assertInstanceOf(ExchangeInterface::class, $exchange);
        $this->assertEquals('binance', $exchange->getName());
    }

    public function testExchangeReturnsOkxInstance()
    {
        $config = $this->createConfig([
            'okx' => [
                'base_url' => 'https://www.okx.com',
                'api_key' => 'test_key',
                'secret' => 'test_secret',
                'passphrase' => 'test_pass',
            ],
        ]);
        $logger = $this->createLogger($config);
        $manager = new ExchangeManager($config, $logger);

        $exchange = $manager->exchange('okx');
        $this->assertInstanceOf(OkxExchange::class, $exchange);
        $this->assertEquals('okx', $exchange->getName());
    }

    public function testExchangeLazyLoading()
    {
        // 同一交易所多次获取返回同一实例（懒加载缓存）
        $config = $this->createConfig([
            'binance' => [
                'api_key' => 'test_key',
                'secret' => 'test_secret',
            ],
        ]);
        $logger = $this->createLogger($config);
        $manager = new ExchangeManager($config, $logger);

        $ex1 = $manager->exchange('binance');
        $ex2 = $manager->exchange('binance');
        $this->assertSame($ex1, $ex2);
    }

    // ========== 错误处理测试 ==========

    public function testExchangeThrowsForUnregistered()
    {
        $config = $this->createConfig();
        $logger = $this->createLogger($config);
        $manager = new ExchangeManager($config, $logger);

        $this->expectException(ExchangeException::class);
        $this->expectExceptionMessage("Exchange 'gate' is not registered");
        $manager->exchange('gate');
    }

    public function testExchangeThrowsForMissingApiKey()
    {
        $config = $this->createConfig();
        $logger = $this->createLogger($config);
        $manager = new ExchangeManager($config, $logger);

        $this->expectException(ExchangeException::class);
        $this->expectExceptionMessage("no API key configured");
        $manager->exchange('binance');
    }

    public function testGetDefaultExchangeThrowsWhenNoDefault()
    {
        // 无默认交易所，无 API Key
        $config = $this->createConfig(['default' => '']);
        $logger = $this->createLogger($config);
        $manager = new ExchangeManager($config, $logger);

        $this->expectException(ExchangeException::class);
        $this->expectExceptionMessage("No default exchange configured");
        $manager->getDefaultExchange();
    }

    // ========== 活跃交易所测试 ==========

    public function testGetActiveExchanges()
    {
        $config = $this->createConfig([
            'binance' => [
                'api_key' => 'test_key',
                'secret' => 'test_secret',
            ],
        ]);
        $logger = $this->createLogger($config);
        $manager = new ExchangeManager($config, $logger);

        $active = $manager->getActiveExchanges();
        $this->assertContains('binance', $active);
        $this->assertNotContains('okx', $active);
    }

    // ========== 动态注册测试 ==========

    public function testRegisterCustomAdapter()
    {
        $config = $this->createConfig();
        $logger = $this->createLogger($config);
        $manager = new ExchangeManager($config, $logger);

        $manager->registerAdapter('mock', MockExchange::class);
        $this->assertContains('mock', $manager->getRegisteredExchanges());
    }

    public function testRegisterAdapterThrowsForInvalidClass()
    {
        $config = $this->createConfig();
        $logger = $this->createLogger($config);
        $manager = new ExchangeManager($config, $logger);

        $this->expectException(ExchangeException::class);
        $this->expectExceptionMessage("must implement ExchangeInterface");
        $manager->registerAdapter('invalid', '\stdClass');
    }
}

/**
 * 模拟交易所适配器（用于测试自定义注册）
 */
class MockExchange implements ExchangeInterface
{
    public function getTicker(string $symbol): array
    {
        return ['symbol' => $symbol, 'price' => 0.0, 'timestamp' => 0];
    }

    public function getOrderBook(string $symbol, int $limit = 100): array
    {
        return ['bids' => [], 'asks' => []];
    }

    public function getKlines(string $symbol, string $interval, int $limit = 100): array
    {
        return [];
    }

    public function getTrades(string $symbol, int $limit = 100): array
    {
        return [];
    }

    public function getServerTime(): int
    {
        return 0;
    }

    public function getBalance(): array
    {
        return [];
    }

    public function createOrder(array $params): array
    {
        return ['id' => 'mock_1'];
    }

    public function cancelOrder(string $orderId, string $symbol): array
    {
        return ['id' => $orderId, 'status' => 'canceled'];
    }

    public function getOrder(string $orderId, string $symbol): array
    {
        return ['id' => $orderId, 'status' => 'open'];
    }

    public function getOpenOrders(string $symbol = ''): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'mock';
    }
}
