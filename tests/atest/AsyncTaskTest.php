<?php

namespace Sikelan\Tests\Atest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Container;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use Sikelan\Task\TaskManager;
use Sikelan\Task\TaskInterface;

/**
 * 异步任务实例测试
 *
 * 测试真实的异步任务执行流程，包括：
 * - 同步任务执行（taskwait）
 * - 异步任务执行（task + callback）
 * - 任务依赖注入
 * - 任务参数传递
 * - 任务异常处理
 */
class AsyncTaskTest extends TestCase
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
        $config->set('app.log_channel', 'async_task_test');

        $this->logger = new Logger($config);
        $this->taskManager = new TaskManager($this->container, $this->logger);
    }

    public function testSyncTaskExecution()
    {
        $mockServer = $this->createMock(\Swoole\Server::class);

        $expectedResult = json_encode([
            'success' => true,
            'data' => ['handled' => true, 'args' => ['test' => 'value']]
        ]);

        $mockServer->method('taskwait')
            ->willReturn($expectedResult);

        $this->taskManager->setServer($mockServer);

        $result = $this->taskManager->sync(AsyncTestTask::class, ['test' => 'value']);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals(['handled' => true, 'args' => ['test' => 'value']], $result['data']);
    }

    public function testAsyncTaskExecutionWithCallback()
    {
        $mockServer = $this->createMock(\Swoole\Server::class);

        $expectedResult = json_encode([
            'success' => true,
            'data' => ['callback_test' => true]
        ]);

        $callbackCalled = false;
        $callbackResult = null;

        $mockServer->method('task')
            ->willReturnCallback(function ($data, $workerId, $callback) use ($expectedResult, &$callbackCalled, &$callbackResult) {
                $callback(null, 1, $expectedResult);
                return 1;
            });

        $this->taskManager->setServer($mockServer);

        $this->taskManager->async(AsyncTestTask::class, [], function ($result) use (&$callbackCalled, &$callbackResult) {
            $callbackCalled = true;
            $callbackResult = $result;
        });

        $this->assertTrue($callbackCalled);
        $this->assertNotNull($callbackResult);
        $this->assertTrue($callbackResult['success']);
    }

    public function testTaskWithDependencyInjection()
    {
        $this->container->set(Logger::class, $this->logger);

        $mockServer = $this->createMock(\Swoole\Server::class);

        $taskData = json_encode([
            'class' => TaskWithDependency::class,
            'args' => ['message' => 'test']
        ]);

        $result = $this->taskManager->onTask($mockServer, 1, 0, $taskData);
        $decoded = json_decode($result, true);

        $this->assertTrue($decoded['success']);
        $this->assertEquals('test', $decoded['data']['message']);
        $this->assertTrue($decoded['data']['logger_injected']);
    }

    public function testTaskWithEmptyArgs()
    {
        $mockServer = $this->createMock(\Swoole\Server::class);

        $taskData = json_encode([
            'class' => AsyncTestTask::class,
            'args' => []
        ]);

        $result = $this->taskManager->onTask($mockServer, 1, 0, $taskData);
        $decoded = json_decode($result, true);

        $this->assertTrue($decoded['success']);
        $this->assertEquals([], $decoded['data']['args']);
    }

    public function testTaskWithComplexArgs()
    {
        $mockServer = $this->createMock(\Swoole\Server::class);

        $complexArgs = [
            'string' => 'hello',
            'number' => 123,
            'array' => ['a', 'b', 'c'],
            'nested' => [
                'key' => 'value',
                'list' => [1, 2, 3]
            ],
            'boolean' => true,
            'null_value' => null
        ];

        $taskData = json_encode([
            'class' => AsyncTestTask::class,
            'args' => $complexArgs
        ]);

        $result = $this->taskManager->onTask($mockServer, 1, 0, $taskData);
        $decoded = json_decode($result, true);

        $this->assertTrue($decoded['success']);
        $this->assertEquals($complexArgs, $decoded['data']['args']);
    }

    public function testTaskThrowsException()
    {
        $mockServer = $this->createMock(\Swoole\Server::class);

        $taskData = json_encode([
            'class' => TaskThrowsException::class,
            'args' => ['should_throw' => true]
        ]);

        $result = $this->taskManager->onTask($mockServer, 1, 0, $taskData);
        $decoded = json_decode($result, true);

        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('Intentional exception', $decoded['error']);
    }

    public function testAsyncTaskWithoutCallback()
    {
        $mockServer = $this->createMock(\Swoole\Server::class);

        $mockServer->method('task')
            ->willReturn(1);

        $this->taskManager->setServer($mockServer);

        $this->taskManager->async(AsyncTestTask::class, ['test' => 'value']);

        $this->assertTrue(true);
    }

    public function testTaskCallbackErrorHandling()
    {
        $mockServer = $this->createMock(\Swoole\Server::class);

        $mockServer->method('task')
            ->willReturnCallback(function ($data, $workerId, $callback) {
                $callback(null, 1, 'invalid json data');
                return 1;
            });

        $this->taskManager->setServer($mockServer);

        $callbackCalled = false;
        $callbackResult = null;

        $this->taskManager->async(AsyncTestTask::class, [], function ($result) use (&$callbackCalled, &$callbackResult) {
            $callbackCalled = true;
            $callbackResult = $result;
        });

        $this->assertTrue($callbackCalled);
        $this->assertNull($callbackResult);
    }

    public function testTaskSerializationAndDeserialization()
    {
        $mockServer = $this->createMock(\Swoole\Server::class);

        $args = [
            'id' => 100,
            'name' => 'Test Task',
            'metadata' => ['version' => '1.0', 'priority' => 'high']
        ];

        $taskData = json_encode([
            'class' => AsyncTestTask::class,
            'args' => $args
        ]);

        $result = $this->taskManager->onTask($mockServer, 1, 0, $taskData);
        $decoded = json_decode($result, true);

        $this->assertTrue($decoded['success']);
        $this->assertEquals($args, $decoded['data']['args']);
    }
}

/**
 * 基础测试任务类
 */
class AsyncTestTask implements TaskInterface
{
    public function handle(array $args)
    {
        return ['handled' => true, 'args' => $args];
    }
}

/**
 * 带依赖注入的测试任务类
 */
class TaskWithDependency implements TaskInterface
{
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function handle(array $args)
    {
        $this->logger->info('TaskWithDependency executed', $args);

        return [
            'message' => $args['message'] ?? '',
            'logger_injected' => $this->logger instanceof Logger
        ];
    }
}

/**
 * 抛出异常的测试任务类
 */
class TaskThrowsException implements TaskInterface
{
    public function handle(array $args)
    {
        if (isset($args['should_throw']) && $args['should_throw']) {
            throw new \RuntimeException('Intentional exception for testing');
        }

        return ['success' => true];
    }
}
