<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Container;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use Sikelan\Crontab\Crontab;

/**
 * Crontab 全覆盖测试
 */
class CrontabTest extends TestCase
{
    private Container $container;
    private Logger $logger;
    private Crontab $crontab;

    protected function setUp(): void
    {
        $this->container = new Container();

        $config = new Config();
        $config->set('app.log_level', 'debug');
        $config->set('app.log_path', sys_get_temp_dir());
        $config->set('app.log_channel', 'test');

        $this->logger = new Logger($config);
        $this->crontab = new Crontab($this->container, $this->logger);
    }

    public function testConstructorSetsDependencies()
    {
        $reflection = new \ReflectionClass($this->crontab);
        $containerProp = $reflection->getProperty('container');
        $loggerProp = $reflection->getProperty('logger');

        $containerProp->setAccessible(true);
        $loggerProp->setAccessible(true);

        $this->assertSame($this->container, $containerProp->getValue($this->crontab));
        $this->assertSame($this->logger, $loggerProp->getValue($this->crontab));
    }

    public function testAddTask()
    {
        $result = $this->crontab->addTask('test_task', '* * * * *', function () {
            return 'executed';
        });

        $this->assertSame($this->crontab, $result); // 支持链式调用

        $tasks = $this->crontab->getTasks();
        $this->assertArrayHasKey('test_task', $tasks);
        $this->assertEquals('* * * * *', $tasks['test_task']['cron']);
    }

    public function testRemoveTask()
    {
        $this->crontab->addTask('task1', '* * * * *', function () {
        });
        $this->crontab->addTask('task2', '* * * * *', function () {
        });

        $this->crontab->removeTask('task1');

        $tasks = $this->crontab->getTasks();
        $this->assertArrayNotHasKey('task1', $tasks);
        $this->assertArrayHasKey('task2', $tasks);
    }

    public function testRemoveNonExistentTask()
    {
        // 不应该抛出异常
        $result = $this->crontab->removeTask('non_existent');
        $this->assertSame($this->crontab, $result);
    }

    public function testGetTasks()
    {
        $this->crontab->addTask('task1', '* * * * *', function () {
        });
        $this->crontab->addTask('task2', '0 * * * *', function () {
        });

        $tasks = $this->crontab->getTasks();

        $this->assertCount(2, $tasks);
        $this->assertEquals('* * * * *', $tasks['task1']['cron']);
        $this->assertEquals('0 * * * *', $tasks['task2']['cron']);
    }

    public function testGetNextRunTimeWithValidCron()
    {
        // 测试标准 cron 表达式
        $reflection = new \ReflectionMethod($this->crontab, 'getNextRunTime');
        $reflection->setAccessible(true);

        // 每分钟
        $nextRun = $reflection->invoke($this->crontab, '* * * * *');
        $this->assertIsInt($nextRun);
        $this->assertGreaterThan(time(), $nextRun);

        // 每天午夜
        $nextRun = $reflection->invoke($this->crontab, '0 0 * * *');
        $this->assertIsInt($nextRun);
    }

    public function testGetNextRunTimeWithInvalidCron()
    {
        $reflection = new \ReflectionMethod($this->crontab, 'getNextRunTime');
        $reflection->setAccessible(true);

        // 无效的 cron 表达式（不是5个字段）
        $result = $reflection->invoke($this->crontab, '* * *');
        $this->assertFalse($result);
    }

    public function testParseFieldAsterisk()
    {
        $reflection = new \ReflectionMethod($this->crontab, 'parseField');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($this->crontab, '*', 10, 0, 23);
        $this->assertEquals(10, $result);
    }

    public function testParseFieldWithStep()
    {
        $reflection = new \ReflectionMethod($this->crontab, 'parseField');
        $reflection->setAccessible(true);

        // */5 从 0 开始，当前是 2，结果应该是 5（第一个 >= 2 的值）
        $result = $reflection->invoke($this->crontab, '*/5', 2, 0, 23);
        $this->assertEquals(5, $result);

        // */5 从 0 开始，当前是 0，结果应该是 0
        $result = $reflection->invoke($this->crontab, '*/5', 0, 0, 23);
        $this->assertEquals(0, $result);
    }

    public function testParseFieldWithRange()
    {
        $reflection = new \ReflectionMethod($this->crontab, 'parseField');
        $reflection->setAccessible(true);

        // 5-10, 当前是 7
        $result = $reflection->invoke($this->crontab, '5-10', 7, 0, 23);
        $this->assertEquals(7, $result);

        // 5-10, 当前是 12（超出范围）
        $result = $reflection->invoke($this->crontab, '5-10', 12, 0, 23);
        $this->assertEquals(5, $result);
    }

    public function testParseFieldWithList()
    {
        $reflection = new \ReflectionMethod($this->crontab, 'parseField');
        $reflection->setAccessible(true);

        // 1,3,5, 当前是 3 -> 结果是 3
        $result = $reflection->invoke($this->crontab, '1,3,5', 3, 0, 5);
        $this->assertEquals(3, $result);

        // 1,3,5, 当前是 4 -> 4不在列表中，第一个 >= 4 的是 5
        $result = $reflection->invoke($this->crontab, '1,3,5', 4, 0, 5);
        $this->assertEquals(5, $result);

        // 1,3,5, 当前是 0 -> 结果是 1（第一个值）
        $result = $reflection->invoke($this->crontab, '1,3,5', 0, 0, 5);
        $this->assertEquals(1, $result);
    }

    public function testParseFieldWithSingleValue()
    {
        $reflection = new \ReflectionMethod($this->crontab, 'parseField');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($this->crontab, '15', 10, 0, 59);
        $this->assertEquals(15, $result);
    }

    public function testAllPublicMethodsExist()
    {
        $expectedMethods = ['addTask', 'removeTask', 'start', 'stop', 'getTasks'];

        foreach ($expectedMethods as $method) {
            $this->assertTrue(
                method_exists($this->crontab, $method),
                "Method {$method} should exist"
            );
        }
    }

    public function testAddTaskMethodSignature()
    {
        $method = new \ReflectionMethod($this->crontab, 'addTask');
        $params = $method->getParameters();

        $this->assertCount(3, $params);
        $this->assertEquals('name', $params[0]->getName());
        $this->assertEquals('cronExpr', $params[1]->getName());
        $this->assertEquals('callback', $params[2]->getName());
    }

    public function testRemoveTaskMethodSignature()
    {
        $method = new \ReflectionMethod($this->crontab, 'removeTask');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('name', $params[0]->getName());
    }

    public function testTasksInitiallyEmpty()
    {
        $reflection = new \ReflectionClass($this->crontab);
        $prop = $reflection->getProperty('tasks');
        $prop->setAccessible(true);

        $this->assertEmpty($prop->getValue($this->crontab));
    }

    public function testTimersInitiallyEmpty()
    {
        $reflection = new \ReflectionClass($this->crontab);
        $prop = $reflection->getProperty('timers');
        $prop->setAccessible(true);

        $this->assertEmpty($prop->getValue($this->crontab));
    }
}
