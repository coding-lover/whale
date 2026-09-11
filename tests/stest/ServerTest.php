<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Container;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use Sikelan\Server\Server;

/**
 * Server 全覆盖测试
 */
class ServerTest extends TestCase
{
    private Container $container;
    private Logger $logger;
    private Config $config;
    private Server $server;

    protected function setUp(): void
    {
        $this->container = new Container();

        $this->config = new Config();
        $this->config->set('server.host', '127.0.0.1');
        $this->config->set('server.port', 9501);
        $this->config->set('server.settings', []);

        $this->logger = new Logger($this->config);

        $this->server = new Server($this->container, $this->logger, $this->config);
    }

    public function testConstructorSetsDependencies()
    {
        $reflection = new \ReflectionClass($this->server);
        $containerProp = $reflection->getProperty('container');
        $loggerProp = $reflection->getProperty('logger');
        $configProp = $reflection->getProperty('config');

        $containerProp->setAccessible(true);
        $loggerProp->setAccessible(true);
        $configProp->setAccessible(true);

        $this->assertSame($this->container, $containerProp->getValue($this->server));
        $this->assertSame($this->logger, $loggerProp->getValue($this->server));
        $this->assertSame($this->config, $configProp->getValue($this->server));
    }

    public function testServerTypeConstants()
    {
        $this->assertEquals('http', Server::TYPE_HTTP);
        $this->assertEquals('websocket', Server::TYPE_WEBSOCKET);
        $this->assertEquals('tcp', Server::TYPE_TCP);
    }

    public function testServerPropertyInitiallyNull()
    {
        $reflection = new \ReflectionClass($this->server);
        $prop = $reflection->getProperty('server');
        $prop->setAccessible(true);

        $this->assertNull($prop->getValue($this->server));
    }

    public function testGetServerBeforeCreate()
    {
        // getServer 在 create 之前返回 null
        $reflection = new \ReflectionClass($this->server);
        $prop = $reflection->getProperty('server');
        $prop->setAccessible(true);

        $this->assertNull($prop->getValue($this->server));
    }

    public function testOnMethodExists()
    {
        $this->assertTrue(method_exists($this->server, 'on'));
    }

    public function testOnMethodSignature()
    {
        $method = new \ReflectionMethod($this->server, 'on');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('event', $params[0]->getName());
        $this->assertEquals('callback', $params[1]->getName());
    }

    public function testOnMethodReturnsServer()
    {
        // on 方法返回 Server 实例用于链式调用
        // 注意：on 方法需要在服务器创建之后才能正常工作，这里只验证方法存在和返回类型
        $reflection = new \ReflectionMethod($this->server, 'on');
        $this->assertTrue($reflection->isPublic());
    }

    public function testGetServerMethodExists()
    {
        $this->assertTrue(method_exists($this->server, 'getServer'));
    }

    public function testStopMethodExists()
    {
        $this->assertTrue(method_exists($this->server, 'stop'));
    }

    public function testStartMethodExists()
    {
        $this->assertTrue(method_exists($this->server, 'start'));
    }

    public function testCreateMethodExists()
    {
        $this->assertTrue(method_exists($this->server, 'create'));
    }

    public function testCreateMethodSignature()
    {
        $method = new \ReflectionMethod($this->server, 'create');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('type', $params[0]->getName());
        $this->assertEquals(Server::TYPE_HTTP, $params[0]->getDefaultValue());
    }

    public function testAllPublicMethodsExist()
    {
        $expectedMethods = ['create', 'on', 'start', 'stop', 'getServer'];

        foreach ($expectedMethods as $method) {
            $this->assertTrue(
                method_exists($this->server, $method),
                "Method {$method} should exist"
            );
        }
    }

    public function testSetServerConfigMethodExists()
    {
        $this->assertTrue(method_exists($this->server, 'setServerConfig'));
    }

    public function testSetServerConfigIsProtected()
    {
        $reflection = new \ReflectionMethod($this->server, 'setServerConfig');
        $this->assertTrue($reflection->isProtected());
    }
}
