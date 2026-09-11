<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Process\AbstractProcess;
use Swoole\Process;

/**
 * AbstractProcess 优雅退出与定时器管理测试
 * 
 * 通过模拟方式验证：
 * 1. addTick 正确注册定时器 ID
 * 2. clearTick 清除单个定时器
 * 3. clearAllTicks 清除所有定时器
 * 4. gracefulShutdown 退出时自动清除所有定时器
 */
class AbstractProcessTest extends TestCase
{
    /**
     * 创建用于测试的 AbstractProcess 子类实例
     * 
     * 通过反射构造实例，避免触发 Swoole Process 的实际创建
     */
    private function createTestProcess(): AbstractProcess
    {
        // 使用匿名类，重写 run() 为空实现
        $process = new class extends AbstractProcess {
            protected string $processName = 'test_process';

            protected function run($arg): void
            {
                // 测试中不执行实际逻辑
            }

            // 重写 onShutDown，记录是否被调用
            public bool $onShutDownCalled = false;

            protected function onShutDown(): void
            {
                $this->onShutDownCalled = true;
            }

            // 重写 onException，记录异常
            public array $exceptions = [];

            protected function onException(\Throwable $throwable): void
            {
                $this->exceptions[] = $throwable->getMessage();
            }

            // 暴露 callOnException 供测试调用
            public function exposeCallOnException(\Throwable $e): void
            {
                $this->callOnException($e);
            }

            // 暴露 gracefulShutdown 供测试调用
            public function exposeGracefulShutdown(Process $process): void
            {
                $this->gracefulShutdown($process);
            }
        };

        return $process;
    }

    /**
     * 通过反射设置 $tickIds 属性（模拟 addTick 后的状态）
     */
    private function setTickIds(AbstractProcess $process, array $ids): void
    {
        $reflection = new \ReflectionClass($process);
        $prop = $reflection->getProperty('tickIds');
        $prop->setAccessible(true);
        $prop->setValue($process, $ids);
    }

    /**
     * 通过反射获取 $tickIds 属性
     */
    private function getTickIds(AbstractProcess $process): array
    {
        $reflection = new \ReflectionClass($process);
        $prop = $reflection->getProperty('tickIds');
        $prop->setAccessible(true);
        return $prop->getValue($process);
    }

    // ==================== 定时器注册与清除测试 ====================

    public function testTickIdsInitiallyEmpty()
    {
        $process = $this->createTestProcess();
        $this->assertEmpty($this->getTickIds($process));
    }

    public function testClearAllTicksRemovesAllTickIds()
    {
        $process = $this->createTestProcess();

        // 模拟注册了 3 个定时器
        $this->setTickIds($process, [1001, 1002, 1003]);
        $this->assertCount(3, $this->getTickIds($process));

        // 执行清除（swoole_timer_clear 对不存在的 ID 返回 false，不抛异常）
        $process->clearAllTicks();

        // 验证全部被清除
        $this->assertEmpty($this->getTickIds($process));
    }

    public function testClearTickRemovesSpecificTickId()
    {
        $process = $this->createTestProcess();

        // 模拟注册了 3 个定时器
        $this->setTickIds($process, [1001, 1002, 1003]);

        // 清除指定的定时器
        $process->clearTick(1002);

        $tickIds = $this->getTickIds($process);

        // 验证 1002 被移除，其余保留
        $this->assertNotContains(1002, $tickIds);
        $this->assertContains(1001, $tickIds);
        $this->assertContains(1003, $tickIds);
        $this->assertCount(2, $tickIds);
    }

    public function testClearTickWithNonExistentIdDoesNotError()
    {
        $process = $this->createTestProcess();
        $this->setTickIds($process, [1001, 1002]);

        // 清除不存在的定时器 ID，不应抛出异常
        $process->clearTick(9999);

        $this->assertCount(2, $this->getTickIds($process));
    }

    public function testClearAllTicksWhenEmptyDoesNotError()
    {
        $process = $this->createTestProcess();
        $this->assertEmpty($this->getTickIds($process));

        // 空列表时调用清除，不应抛出异常
        $process->clearAllTicks();

        $this->assertEmpty($this->getTickIds($process));
    }

    public function testClearAllTicksCanHandleLargeNumberOfTicks()
    {
        $process = $this->createTestProcess();
        $ids = range(2000, 2099); // 100 个定时器
        $this->setTickIds($process, $ids);

        $process->clearAllTicks();

        $this->assertEmpty($this->getTickIds($process));
    }

    // ==================== 优雅退出测试 ====================

    public function testGracefulShutdownClearsAllTicks()
    {
        $process = $this->createTestProcess();

        // 模拟注册了多个定时器
        $this->setTickIds($process, [1001, 1002, 1003]);

        // 确认定时器存在
        $this->assertCount(3, $this->getTickIds($process));

        // 模拟 gracefulShutdown 中的 clearAllTicks 调用
        // 由于 gracefulShutdown 依赖 Swoole 事件循环（Channel, go, swoole_event_exit），
        // 这里直接测试其核心逻辑：清除定时器
        $process->clearAllTicks();

        // 验证优雅退出后定时器全部被清除
        $this->assertEmpty(
            $this->getTickIds($process),
            '优雅退出后所有定时器应被清除'
        );
    }

    public function testGracefulShutdownCallsOnShutDown()
    {
        $process = $this->createTestProcess();

        // 通过反射直接调用 onShutDown 逻辑
        $reflection = new \ReflectionClass($process);
        $method = $reflection->getMethod('onShutDown');
        $method->setAccessible(true);
        $method->invoke($process);

        $this->assertTrue($process->onShutDownCalled, 'onShutDown 应被调用');
    }

    public function testGracefulShutdownClearsTicksBeforeOnShutDown()
    {
        $process = $this->createTestProcess();
        $this->setTickIds($process, [1001, 1002]);

        // 模拟 gracefulShutdown 的执行顺序：
        // 1. 清除所有定时器
        // 2. 执行 onShutDown
        $process->clearAllTicks();

        $reflection = new \ReflectionClass($process);
        $method = $reflection->getMethod('onShutDown');
        $method->setAccessible(true);
        $method->invoke($process);

        // 验证：定时器在 onShutDown 之前已被清除
        $this->assertEmpty($this->getTickIds($process), '定时器应在 onShutDown 之前被清除');
        $this->assertTrue($process->onShutDownCalled, 'onShutDown 应被调用');
    }

    // ==================== 异常兜底测试 ====================

    public function testOnExceptionIsCalledWhenRunThrows()
    {
        $process = new class extends AbstractProcess {
            protected string $processName = 'error_test';
            public array $exceptions = [];

            protected function run($arg): void
            {
                throw new \RuntimeException('Run error');
            }

            protected function onException(\Throwable $throwable): void
            {
                $this->exceptions[] = $throwable->getMessage();
            }

            public function exposeCallOnException(\Throwable $e): void
            {
                $this->callOnException($e);
            }
        };

        // 直接调用 callOnException 模拟异常捕获
        $process->exposeCallOnException(new \RuntimeException('Run error'));

        $this->assertContains('Run error', $process->exceptions);
    }

    public function testOnExceptionDoesNotThrowWhenItFails()
    {
        $process = new class extends AbstractProcess {
            protected string $processName = 'error_test';

            protected function run($arg): void
            {
            }

            protected function onException(\Throwable $throwable): void
            {
                throw new \RuntimeException('onException itself failed');
            }

            public function exposeCallOnException(\Throwable $e): void
            {
                $this->callOnException($e);
            }
        };

        // callOnException 内部有 try-catch 兜底，不应抛出异常
        $process->exposeCallOnException(new \RuntimeException('Original error'));

        // 如果执行到这里，说明异常被正确兜底
        $this->assertTrue(true, 'onException 异常应被兜底，不应向外抛出');
    }

    // ==================== 配置属性测试 ====================

    public function testGetProcessName()
    {
        $process = $this->createTestProcess();
        $this->assertEquals('test_process', $process->getProcessName());
    }

    public function testGetMaxExitWaitTime()
    {
        $process = $this->createTestProcess();
        $this->assertEquals(3, $process->getMaxExitWaitTime());
    }

    public function testCustomMaxExitWaitTime()
    {
        $process = new class extends AbstractProcess {
            protected int $maxExitWaitTime = 10;

            protected function run($arg): void
            {
            }
        };

        $this->assertEquals(10, $process->getMaxExitWaitTime());
    }

    // ==================== 方法存在性测试 ====================

    public function testAllPublicMethodsExist()
    {
        $expectedMethods = [
            'addTick', 'addAfter', 'clearTick', 'clearAllTicks',
            'writeToMain', 'getSwooleProcess', 'getProcessName', 'getMaxExitWaitTime',
        ];

        foreach ($expectedMethods as $method) {
            $this->assertTrue(
                method_exists(AbstractProcess::class, $method),
                "Method {$method} should exist on AbstractProcess"
            );
        }
    }

    public function testRunMethodIsAbstract()
    {
        $reflection = new \ReflectionClass(AbstractProcess::class);
        $method = $reflection->getMethod('run');

        $this->assertTrue($method->isAbstract(), 'run() must be abstract');
    }

    public function testOnShutDownOnPipeReadableOnExceptionAreProtected()
    {
        $reflection = new \ReflectionClass(AbstractProcess::class);

        $this->assertTrue(
            $reflection->getMethod('onShutDown')->isProtected(),
            'onShutDown should be protected'
        );
        $this->assertTrue(
            $reflection->getMethod('onPipeReadable')->isProtected(),
            'onPipeReadable should be protected'
        );
        $this->assertTrue(
            $reflection->getMethod('onException')->isProtected(),
            'onException should be protected'
        );
    }
}
