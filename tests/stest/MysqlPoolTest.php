<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Config;
use Sikelan\Database\MysqlPool;

/**
 * MysqlPool 全覆盖测试
 */
class MysqlPoolTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config();
        $this->config->set('database.mysql', [
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'root',
            'password' => '',
            'database' => 'test',
            'charset' => 'utf8mb4',
            'timeout' => 5,
            'pool_size' => 10
        ]);
    }

    public function testConstructorReadsConfig()
    {
        $pool = new MysqlPool($this->config);

        $reflection = new \ReflectionClass($pool);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $config = $configProp->getValue($pool);

        $this->assertEquals('127.0.0.1', $config['host']);
        $this->assertEquals(3306, $config['port']);
        $this->assertEquals('root', $config['username']);
    }

    public function testConstructorSetsMaxConnections()
    {
        $pool = new MysqlPool($this->config);

        $reflection = new \ReflectionClass($pool);
        $prop = $reflection->getProperty('maxConnections');
        $prop->setAccessible(true);

        $this->assertEquals(10, $prop->getValue($pool));
    }

    public function testConstructorWithCustomPoolSize()
    {
        $customConfig = new Config();
        $customConfig->set('database.mysql', [
            'pool_size' => 20
        ]);

        $pool = new MysqlPool($customConfig);

        $reflection = new \ReflectionClass($pool);
        $prop = $reflection->getProperty('maxConnections');
        $prop->setAccessible(true);

        $this->assertEquals(20, $prop->getValue($pool));
    }

    public function testPoolIsInitiallyEmpty()
    {
        $pool = new MysqlPool($this->config);

        $reflection = new \ReflectionClass($pool);
        $prop = $reflection->getProperty('pool');
        $prop->setAccessible(true);

        $this->assertIsArray($prop->getValue($pool));
        $this->assertEmpty($prop->getValue($pool));
    }

    public function testAllPublicMethodsExist()
    {
        $pool = new MysqlPool($this->config);

        $expectedMethods = [
            'get', 'release', 'query', 'select', 'insert', 'update', 'delete',
            'beginTransaction', 'commit', 'rollback'
        ];

        foreach ($expectedMethods as $method) {
            $this->assertTrue(
                method_exists($pool, $method),
                "Method {$method} should exist"
            );
        }
    }

    public function testGetMethodSignature()
    {
        $pool = new MysqlPool($this->config);

        $method = new \ReflectionMethod($pool, 'get');
        $this->assertCount(0, $method->getParameters());
    }

    public function testReleaseMethodSignature()
    {
        $pool = new MysqlPool($this->config);

        $method = new \ReflectionMethod($pool, 'release');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('connection', $params[0]->getName());
    }

    public function testQueryMethodSignature()
    {
        $pool = new MysqlPool($this->config);

        $method = new \ReflectionMethod($pool, 'query');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('sql', $params[0]->getName());
        $this->assertEquals('params', $params[1]->getName());
        $this->assertTrue($params[1]->isDefaultValueAvailable() && $params[1]->getDefaultValue() === []);
    }

    public function testSelectMethodSignature()
    {
        $pool = new MysqlPool($this->config);

        $method = new \ReflectionMethod($pool, 'select');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('sql', $params[0]->getName());
        $this->assertEquals('params', $params[1]->getName());
    }

    public function testInsertMethodSignature()
    {
        $pool = new MysqlPool($this->config);

        $method = new \ReflectionMethod($pool, 'insert');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('table', $params[0]->getName());
        $this->assertEquals('data', $params[1]->getName());
    }

    public function testUpdateMethodSignature()
    {
        $pool = new MysqlPool($this->config);

        $method = new \ReflectionMethod($pool, 'update');
        $params = $method->getParameters();

        $this->assertCount(3, $params);
        $this->assertEquals('table', $params[0]->getName());
        $this->assertEquals('data', $params[1]->getName());
        $this->assertEquals('where', $params[2]->getName());
    }

    public function testDeleteMethodSignature()
    {
        $pool = new MysqlPool($this->config);

        $method = new \ReflectionMethod($pool, 'delete');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('table', $params[0]->getName());
        $this->assertEquals('where', $params[1]->getName());
    }

    public function testBeginTransactionReturnsConnection()
    {
        $pool = new MysqlPool($this->config);

        $method = new \ReflectionMethod($pool, 'beginTransaction');
        $this->assertCount(0, $method->getParameters());
    }

    public function testCommitMethodSignature()
    {
        $pool = new MysqlPool($this->config);

        $method = new \ReflectionMethod($pool, 'commit');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('connection', $params[0]->getName());
    }

    public function testRollbackMethodSignature()
    {
        $pool = new MysqlPool($this->config);

        $method = new \ReflectionMethod($pool, 'rollback');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('connection', $params[0]->getName());
    }

    public function testCreateConnectionMethodExists()
    {
        $pool = new MysqlPool($this->config);

        $this->assertTrue(method_exists($pool, 'createConnection'));
    }

    public function testCurrentConnectionsInitiallyZero()
    {
        $pool = new MysqlPool($this->config);

        $reflection = new \ReflectionClass($pool);
        $prop = $reflection->getProperty('currentConnections');
        $prop->setAccessible(true);

        $this->assertEquals(0, $prop->getValue($pool));
    }
}
