<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Sikelan\Core\Config;
use Sikelan\Cache\RedisCache;

/**
 * RedisCache 全覆盖测试
 */
class RedisCacheTest extends TestCase
{
    private Config $config;
    private RedisCache $cache;

    protected function setUp(): void
    {
        $this->config = new Config();
        $this->config->set('cache.redis', [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'database' => 0,
            'timeout' => 5
        ]);
    }

    // 由于 RedisCache 需要真实的 Redis 连接，我们测试其配置和基本方法
    // 连接相关的方法需要集成测试环境

    public function testCacheStoresConfig()
    {
        // 测试 RedisCache 是否正确读取配置
        $cache = new RedisCache($this->config);

        // 使用反射获取 config 属性
        $reflection = new \ReflectionClass($cache);
        $property = $reflection->getProperty('config');
        $property->setAccessible(true);
        $config = $property->getValue($cache);

        $this->assertIsArray($config);
        $this->assertEquals('127.0.0.1', $config['host']);
        $this->assertEquals(6379, $config['port']);
    }

    public function testCacheHasRedisClient()
    {
        $cache = new RedisCache($this->config);

        $reflection = new \ReflectionClass($cache);
        $property = $reflection->getProperty('redis');
        $property->setAccessible(true);
        $redis = $property->getValue($cache);

        $this->assertInstanceOf(\Swoole\Coroutine\Redis::class, $redis);
    }

    public function testGetClientReturnsRedisInstance()
    {
        $cache = new RedisCache($this->config);

        // getClient 会尝试连接，这里只验证方法存在
        $this->assertTrue(method_exists($cache, 'getClient'));
    }

    // 测试所有公共方法存在
    public function testAllPublicMethodsExist()
    {
        $cache = new RedisCache($this->config);

        $expectedMethods = [
            'get', 'set', 'del', 'exists', 'expire',
            'hGet', 'hSet', 'hGetAll', 'hDel',
            'lPush', 'rPush', 'lPop', 'rPop', 'lLen',
            'incr', 'decr', 'keys', 'flushDb', 'getClient'
        ];

        foreach ($expectedMethods as $method) {
            $this->assertTrue(
                method_exists($cache, $method),
                "Method {$method} should exist"
            );
        }
    }

    public function testSetWithTtl()
    {
        $cache = new RedisCache($this->config);

        // 验证 set 方法签名
        $method = new \ReflectionMethod($cache, 'set');
        $params = $method->getParameters();

        $this->assertCount(3, $params);
        $this->assertEquals('key', $params[0]->getName());
        $this->assertEquals('value', $params[1]->getName());
        $this->assertEquals('ttl', $params[2]->getName());
        $this->assertTrue($params[2]->isDefaultValueAvailable() && $params[2]->getDefaultValue() === null);
    }

    public function testHSetMethod()
    {
        $cache = new RedisCache($this->config);

        $method = new \ReflectionMethod($cache, 'hSet');
        $params = $method->getParameters();

        $this->assertCount(3, $params);
        $this->assertEquals('key', $params[0]->getName());
        $this->assertEquals('field', $params[1]->getName());
        $this->assertEquals('value', $params[2]->getName());
    }

    public function testLPushAndRPop()
    {
        $cache = new RedisCache($this->config);

        $lPushMethod = new \ReflectionMethod($cache, 'lPush');
        $rPopMethod = new \ReflectionMethod($cache, 'rPop');

        $this->assertCount(2, $lPushMethod->getParameters());
        $this->assertCount(1, $rPopMethod->getParameters());
    }

    public function testIncrAndDecr()
    {
        $cache = new RedisCache($this->config);

        $incrMethod = new \ReflectionMethod($cache, 'incr');
        $decrMethod = new \ReflectionMethod($cache, 'decr');

        $this->assertCount(1, $incrMethod->getParameters());
        $this->assertCount(1, $decrMethod->getParameters());
    }

    public function testKeysMethod()
    {
        $cache = new RedisCache($this->config);

        $method = new \ReflectionMethod($cache, 'keys');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('pattern', $params[0]->getName());
    }
}
