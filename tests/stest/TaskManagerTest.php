<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Container;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use Sikelan\Task\TaskManager;
use Sikelan\Task\TaskInterface;

/**
 * TaskManager 全覆盖测试
 */
class TaskManagerTest extends TestCase
{
    private Container $container;
    private Logger $logger;
    private TaskManager $taskManager;

    protected function setUp(): void
    {
        $this->container = new Container();

        $config = new Config();
        $config->set('app.log_level', 'debug');
        $config->set('app.log_path', sys_get_temp_dir());
        $config->set('app.log_channel', 'test');

        $this->logger = new Logger($config);
        $this->taskManager = new TaskManager($this->container, $this->logger);
    }

    public function testConstructorSetsDependencies()
    {
        $reflection = new \ReflectionClass($this->taskManager);
        $containerProp = $reflection->getProperty('container');
        $loggerProp = $reflection->getProperty('logger');

        $containerProp->setAccessible(true);
        $loggerProp->setAccessible(true);

        $this->assertSame($this->container, $containerProp->getValue($this->taskManager));
        $this->assertSame($this->logger, $loggerProp->getValue($this->taskManager));
    }

    public function testSetServer()
    {
        $mockServer = $this->createMock(\Swoole\Server::class);

        $result = $this->taskManager->setServer($mockServer);

        $reflection = new \ReflectionClass($this->taskManager);
        $prop = $reflection->getProperty('server');
        $prop->setAccessible(true);

        $this->assertSame($mockServer, $prop->getValue($this->taskManager));
        $this->assertSame($this->taskManager, $result); // 支持链式调用
    }

    public function testAsyncThrowsExceptionWhenServerNotSet()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server instance not set');

        $this->taskManager->async('SomeTaskClass');
    }

    public function testSyncThrowsExceptionWhenServerNotSet()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server instance not set');

        $this->taskManager->sync('SomeTaskClass');
    }

    public function testOnTaskWithMissingClass()
    {
        // 测试缺少 class 键的情况
        $mockServer = $this->createMock(\Swoole\Server::class);
        $taskData = json_encode(['args' => []]); // 没有 'class' 键
        $result = $this->taskManager->onTask($mockServer, 1, 0, $taskData);
        $decoded = json_decode($result, true);

        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('not specified', $decoded['error']);
    }

    public function testOnTaskWithNonExistentClass()
    {
        $mockServer = $this->createMock(\Swoole\Server::class);
        $taskData = json_encode([
            'class' => 'NonExistentTaskClass',
            'args' => []
        ]);

        $result = $this->taskManager->onTask($mockServer, 1, 0, $taskData);
        $decoded = json_decode($result, true);

        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('not found', $decoded['error']);
    }

    public function testOnTaskWithInvalidTaskInterface()
    {
        $mockServer = $this->createMock(\Swoole\Server::class);
        // 使用一个存在的但不实现 TaskInterface 的类
        $taskData = json_encode([
            'class' => 'Sikelan\Tests\Stest\NonExistentClass',
            'args' => []
        ]);

        $result = $this->taskManager->onTask($mockServer, 1, 0, $taskData);
        $decoded = json_decode($result, true);

        $this->assertFalse($decoded['success']);
    }

    public function testAllPublicMethodsExist()
    {
        $expectedMethods = ['setServer', 'async', 'sync', 'onTask', 'onFinish'];

        foreach ($expectedMethods as $method) {
            $this->assertTrue(
                method_exists($this->taskManager, $method),
                "Method {$method} should exist"
            );
        }
    }

    public function testAsyncMethodSignature()
    {
        $method = new \ReflectionMethod($this->taskManager, 'async');
        $params = $method->getParameters();

        $this->assertCount(3, $params);
        $this->assertEquals('taskClass', $params[0]->getName());
        $this->assertEquals('args', $params[1]->getName());
        $this->assertEquals('callback', $params[2]->getName());
        $this->assertTrue($params[2]->isDefaultValueAvailable() && $params[2]->getDefaultValue() === null);
    }

    public function testSyncMethodSignature()
    {
        $method = new \ReflectionMethod($this->taskManager, 'sync');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('taskClass', $params[0]->getName());
        $this->assertEquals('args', $params[1]->getName());
    }

    public function testOnTaskMethodSignature()
    {
        // 新签名为 onTask(SwooleServer $server, ...$args)：
        // 兼容协程模式 ($server, Task) 与同步模式 ($server, $taskId, $workerId, $data)。
        $method = new \ReflectionMethod($this->taskManager, 'onTask');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('server', $params[0]->getName());
        $this->assertEquals('args', $params[1]->getName());
        // 第二个参数必须是可变参数（...$args）
        $this->assertTrue($params[1]->isVariadic(), 'onTask 第二参数应为可变参数 ...$args');
    }

    /**
     * 协程模式（task_enable_coroutine=true）：
     * Swoole 传入一个带 finish()/id/data 的 Task 对象，结果必须通过 finish() 回传，方法返回 null。
     */
    public function testOnTaskCoroutineModeCallsFinish()
    {
        $mockServer = $this->createMock(\Swoole\Server::class);
        $taskData = json_encode([
            'class' => TestTask::class,
            'args'  => ['foo' => 'bar'],
        ]);

        // 模拟 Swoole\Server\Task（final 类无法 mock，用鸭子类型替身）
        $task = new class($taskData) {
            public int $id = 99;
            public string $data;
            public ?string $finishedWith = null;

            public function __construct(string $data)
            {
                $this->data = $data;
            }

            public function finish(string $result): void
            {
                $this->finishedWith = $result;
            }
        };

        $return = $this->taskManager->onTask($mockServer, $task);

        // 协程模式无 return
        $this->assertNull($return);
        // 结果通过 finish() 回传
        $this->assertNotNull($task->finishedWith);
        $decoded = json_decode($task->finishedWith, true);
        $this->assertTrue($decoded['success']);
        $this->assertTrue($decoded['data']['handled']);
        $this->assertSame(['foo' => 'bar'], $decoded['data']['args']);
    }

    /**
     * 协程模式下任务执行失败：也必须通过 finish() 回传错误 JSON，而不是抛异常。
     */
    public function testOnTaskCoroutineModeFailureCallsFinishWithError()
    {
        $mockServer = $this->createMock(\Swoole\Server::class);
        $taskData = json_encode(['args' => []]); // 缺 class

        $task = new class($taskData) {
            public int $id = 100;
            public string $data;
            public ?string $finishedWith = null;

            public function __construct(string $data)
            {
                $this->data = $data;
            }

            public function finish(string $result): void
            {
                $this->finishedWith = $result;
            }
        };

        $return = $this->taskManager->onTask($mockServer, $task);

        $this->assertNull($return);
        $decoded = json_decode($task->finishedWith, true);
        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('not specified', $decoded['error']);
    }

    public function testOnFinishMethodSignature()
    {
        $method = new \ReflectionMethod($this->taskManager, 'onFinish');
        $params = $method->getParameters();

        $this->assertCount(3, $params);
        $this->assertEquals('server', $params[0]->getName());
        $this->assertEquals('taskId', $params[1]->getName());
        $this->assertEquals('data', $params[2]->getName());
    }
}

/**
 * 测试用的 Task 实现类
 */
class TestTask implements TaskInterface
{
    public function handle(array $args)
    {
        return ['handled' => true, 'args' => $args];
    }
}
