<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;

/**
 * Framework 单例测试 - 验证框架单例获取功能
 */
class FrameworkTest extends TestCase
{
    protected function setUp(): void
    {
        // 重置 Framework 单例状态（通过反射）
        $reflection = new \ReflectionClass(\Sikelan\Framework::class);
        $property = $reflection->getProperty('_instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    public function testFrameworkGetInstanceReturnsSingleton()
    {
        $instance1 = \Sikelan\Framework::getInstance();
        $instance2 = \Sikelan\Framework::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testFrameworkGetInstanceReturnsFramework()
    {
        $instance = \Sikelan\Framework::getInstance();

        $this->assertInstanceOf(\Sikelan\Framework::class, $instance);
    }

    public function testFrameworkHasContainer()
    {
        $instance = \Sikelan\Framework::getInstance();

        $this->assertNotNull($instance->getContainer());
        $this->assertInstanceOf(\Sikelan\Core\Container::class, $instance->getContainer());
    }

    public function testFrameworkHasRouter()
    {
        $instance = \Sikelan\Framework::getInstance();

        $this->assertNotNull($instance->getRouter());
        $this->assertInstanceOf(\Sikelan\Http\Router::class, $instance->getRouter());
    }

    public function testFrameworkHasLogger()
    {
        $instance = \Sikelan\Framework::getInstance();

        $this->assertNotNull($instance->getLogger());
        $this->assertInstanceOf(\Sikelan\Core\Logger::class, $instance->getLogger());
    }

    public function testFrameworkHasConfig()
    {
        $instance = \Sikelan\Framework::getInstance();

        $this->assertNotNull($instance->getConfig());
        $this->assertInstanceOf(\Sikelan\Core\Config::class, $instance->getConfig());
    }

    public function testFrameworkHasTaskManager()
    {
        $instance = \Sikelan\Framework::getInstance();

        $this->assertNotNull($instance->getTaskManager());
        $this->assertInstanceOf(\Sikelan\Task\TaskManager::class, $instance->getTaskManager());
    }

    public function testFrameworkHasCrontab()
    {
        $instance = \Sikelan\Framework::getInstance();

        $this->assertNotNull($instance->getCrontab());
        $this->assertInstanceOf(\Sikelan\Crontab\Crontab::class, $instance->getCrontab());
    }

    public function testFrameworkGetCacheLazilyInitialized()
    {
        $instance = \Sikelan\Framework::getInstance();

        // cache 是延迟初始化的，初始为 null
        // 只有调用 getCache() 后才会创建
        $cache = $instance->getCache();
        $this->assertNotNull($cache);
    }

    public function testFrameworkGetDbLazilyInitialized()
    {
        $instance = \Sikelan\Framework::getInstance();

        // db 是延迟初始化的，初始为 null
        // 只有调用 getDb() 后才会创建
        $db = $instance->getDb();
        $this->assertNotNull($db);
    }

    public function testFrameworkGetProcessManagerLazilyInitialized()
    {
        $instance = \Sikelan\Framework::getInstance();

        // processManager 是延迟初始化的，初始为 null
        // 只有调用 getProcessManager() 后才会创建
        $pm = $instance->getProcessManager();
        $this->assertNotNull($pm);
    }

    public function testFrameworkGetStatus()
    {
        $instance = \Sikelan\Framework::getInstance();

        $status = $instance->getStatus();

        $this->assertIsArray($status);
        $this->assertArrayHasKey('timestamp', $status);
        $this->assertArrayHasKey('datetime', $status);
        $this->assertArrayHasKey('uptime', $status);
        $this->assertArrayHasKey('uptime_human', $status);
        $this->assertArrayHasKey('main_server', $status);
        $this->assertArrayHasKey('listen_address', $status);
        $this->assertArrayHasKey('listen_port', $status);
        $this->assertArrayHasKey('worker_num', $status);
        $this->assertArrayHasKey('swoole_version', $status);
        $this->assertArrayHasKey('php_version', $status);
        $this->assertArrayHasKey('framework_version', $status);
        $this->assertArrayHasKey('environment', $status);
        $this->assertArrayHasKey('memory', $status);
        $this->assertArrayHasKey('server_stats', $status);
        $this->assertArrayHasKey('routes_count', $status);

        // 验证 memory 子结构
        $this->assertIsArray($status['memory']);
        $this->assertArrayHasKey('usage', $status['memory']);
        $this->assertArrayHasKey('usage_human', $status['memory']);
        $this->assertArrayHasKey('peak', $status['memory']);
        $this->assertArrayHasKey('peak_human', $status['memory']);

        // 验证 routes_count 为整数
        $this->assertIsInt($status['routes_count']);
    }

    protected function tearDown(): void
    {
        // 清理 - 重置单例
        $reflection = new \ReflectionClass(\Sikelan\Framework::class);
        $property = $reflection->getProperty('_instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
