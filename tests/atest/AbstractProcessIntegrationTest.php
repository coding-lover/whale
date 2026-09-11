<?php

namespace Sikelan\Tests\Atest;

use PHPUnit\Framework\TestCase;
use Sikelan\Process\AbstractProcess;
use Swoole\Process;

/**
 * AbstractProcess 集成测试
 * 
 * 通过 fork 真实子进程，模拟完整的服务生命周期：
 * 1. 进程启动 → 定时器注册 → 定时器实际执行
 * 2. 管道通信（父进程 → 子进程）
 * 3. SIGTERM 信号 → 优雅退出 → 定时器被清除 → onShutDown 被调用
 * 
 * 使用临时文件进行跨进程通信，避免管道阻塞问题
 * 
 * 注意：测试使用 enableCoroutine=false 以兼容 Xdebug 环境
 * 
 * @requires extension swoole
 */
class AbstractProcessIntegrationTest extends TestCase
{
    /**
     * 子进程 PID（用于 tearDown 清理）
     */
    private ?int $childPid = null;

    /**
     * Swoole Process 实例
     */
    private ?Process $swooleProcess = null;

    /**
     * 测试产生的临时文件列表
     */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        // Xdebug 与 Swoole 事件循环不兼容，自动跳过
        if (extension_loaded('xdebug')) {
            $this->markTestSkipped(
                'Swoole 事件循环无法在 Xdebug 环境下运行，请使用 php -c /tmp/php_no_xdebug.ini 运行此测试'
            );
        }
    }

    protected function tearDown(): void
    {
        // 确保子进程被终止
        if ($this->childPid && $this->isProcessRunning($this->childPid)) {
            Process::kill($this->childPid, SIGKILL);
            Process::wait();
        }
        $this->childPid = null;

        // 清理临时文件
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * 检查进程是否仍在运行
     */
    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        $output = [];
        @exec("ps -p {$pid} 2>/dev/null", $output);
        return count($output) > 1;
    }

    /**
     * 创建临时文件用于跨进程通信
     */
    private function createTempFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'sikelan_int_');
        file_put_contents($file, '');
        $this->tempFiles[] = $file;
        return $file;
    }

    /**
     * 轮询等待临时文件中出现指定内容
     * 
     * @param string $file 临时文件路径
     * @param string $expected 期望出现的内容
     * @param float $timeout 超时时间（秒）
     * @return bool 是否在超时前找到内容
     */
    private function waitForLogContent(string $file, string $expected, float $timeout = 5.0): bool
    {
        $start = microtime(true);
        while (microtime(true) - $start < $timeout) {
            $content = @file_get_contents($file);
            if ($content !== false && strpos($content, $expected) !== false) {
                return true;
            }
            usleep(50000); // 50ms 轮询间隔
        }
        return false;
    }

    /**
     * 测试完整生命周期：启动 → 注册定时器 → 优雅退出 → 定时器被清除
     */
    public function testFullLifecycleGracefulShutdown()
    {
        $logFile = $this->createTempFile();

        // 创建测试进程，$logFile 作为 arg 传入
        $testProcess = new class('', $logFile, false, 2, false) extends AbstractProcess {
            protected string $processName = 'lifecycle_test';
            protected int $maxExitWaitTime = 3;

            protected function run($arg): void
            {
                $logFile = $arg;

                // 注册 3 个定时器（短间隔，仅用于测试）
                $this->addTick(100, function () {});
                $this->addTick(200, function () {});
                $this->addTick(300, function () {});

                // 报告注册状态
                file_put_contents($logFile, "TICKS_REGISTERED:3\n", FILE_APPEND);
            }

            protected function onShutDown(): void
            {
                // 通过反射检查剩余定时器数量
                $reflection = new \ReflectionClass($this);
                $prop = $reflection->getProperty('tickIds');
                $prop->setAccessible(true);
                $remaining = count($prop->getValue($this));

                file_put_contents($this->arg, "SHUTDOWN_CALLED\n", FILE_APPEND);
                file_put_contents($this->arg, "REMAINING_TICKS:{$remaining}\n", FILE_APPEND);
            }
        };

        $this->swooleProcess = $testProcess->getSwooleProcess();

        // 启动子进程
        $this->childPid = $this->swooleProcess->start();
        $this->assertGreaterThan(0, $this->childPid, '子进程应成功启动');

        // 等待定时器注册完成
        $registered = $this->waitForLogContent($logFile, 'TICKS_REGISTERED:3', 5);
        $this->assertTrue($registered, '子进程应成功注册 3 个定时器');

        // 等待定时器运行一段时间
        usleep(300000); // 300ms

        // 发送 SIGTERM 触发优雅退出
        Process::kill($this->childPid, SIGTERM);

        // 等待 onShutDown 被调用
        $shutdownCalled = $this->waitForLogContent($logFile, 'SHUTDOWN_CALLED', 5);
        $this->assertTrue($shutdownCalled, '收到 SIGTERM 后 onShutDown 应被调用');

        // 等待剩余定时器数量报告
        $remainingReported = $this->waitForLogContent($logFile, 'REMAINING_TICKS:0', 5);
        $this->assertTrue($remainingReported, '优雅退出后所有定时器应被清除（REMAINING_TICKS 应为 0）');

        // 验证完整日志内容
        $log = file_get_contents($logFile);
        $this->assertStringContainsString('TICKS_REGISTERED:3', $log);
        $this->assertStringContainsString('SHUTDOWN_CALLED', $log);
        $this->assertStringContainsString('REMAINING_TICKS:0', $log);

        // 等待子进程完全退出
        $status = Process::wait();
        $this->assertEquals($this->childPid, $status['pid']);
        $this->childPid = null;
    }

    /**
     * 测试定时器实际执行
     * 
     * 注册一个 100ms 间隔的定时器，验证它在 500ms 内至少执行 3 次
     */
    public function testTimerActuallyExecutes()
    {
        $logFile = $this->createTempFile();

        $testProcess = new class('', $logFile, false, 2, false) extends AbstractProcess {
            protected string $processName = 'timer_exec_test';
            private int $count = 0;

            protected function run($arg): void
            {
                $this->addTick(100, function () {
                    $this->count++;
                    file_put_contents($this->arg, "TICK:{$this->count}\n", FILE_APPEND);
                });
            }
        };

        $this->swooleProcess = $testProcess->getSwooleProcess();
        $this->childPid = $this->swooleProcess->start();

        // 等待至少 3 次定时器执行（100ms × 3 = 300ms，留余量到 3 秒）
        $ok = $this->waitForLogContent($logFile, 'TICK:3', 3);
        $this->assertTrue($ok, '定时器应至少执行 3 次');

        // 验证执行顺序（TICK:1, TICK:2, TICK:3）
        $log = file_get_contents($logFile);
        $this->assertStringContainsString('TICK:1', $log);
        $this->assertStringContainsString('TICK:2', $log);
        $this->assertStringContainsString('TICK:3', $log);

        // 清理：优雅退出
        Process::kill($this->childPid, SIGTERM);
        Process::wait();
        $this->childPid = null;
    }

    /**
     * 测试管道通信
     * 
     * 父进程通过管道发送消息，子进程通过 onPipeReadable 接收
     */
    public function testPipeCommunication()
    {
        $logFile = $this->createTempFile();

        $testProcess = new class('', $logFile, false, 2, false) extends AbstractProcess {
            protected string $processName = 'pipe_test';

            protected function run($arg): void
            {
                // 通知父进程：管道事件监听已就绪
                file_put_contents($this->arg, "READY\n", FILE_APPEND);
            }

            protected function onPipeReadable(Process $process): void
            {
                $msg = trim($process->read());
                file_put_contents($this->arg, "RECEIVED:{$msg}\n", FILE_APPEND);
            }
        };

        $this->swooleProcess = $testProcess->getSwooleProcess();
        $this->childPid = $this->swooleProcess->start();

        // 等待子进程就绪（管道事件监听已注册）
        $ready = $this->waitForLogContent($logFile, 'READY', 3);
        $this->assertTrue($ready, '子进程应报告管道监听已就绪');

        // 父进程通过管道发送消息
        $this->swooleProcess->write("HELLO_CHILD");

        // 等待子进程接收消息
        $received = $this->waitForLogContent($logFile, 'RECEIVED:HELLO_CHILD', 3);
        $this->assertTrue($received, '子进程应通过管道接收到消息');

        // 清理
        Process::kill($this->childPid, SIGTERM);
        Process::wait();
        $this->childPid = null;
    }

    /**
     * 测试优雅退出的执行顺序
     * 
     * 验证 clearAllTicks 在 onShutDown 之前执行
     * 通过 onShutDown 中检查 tickIds 确认定时器已被清除
     */
    public function testShutdownOrderClearsTicksBeforeOnShutDown()
    {
        $logFile = $this->createTempFile();

        $testProcess = new class('', $logFile, false, 2, false) extends AbstractProcess {
            protected string $processName = 'order_test';
            protected int $maxExitWaitTime = 3;

            protected function run($arg): void
            {
                $this->addTick(100, function () {});
                $this->addTick(200, function () {});

                file_put_contents($this->arg, "STARTED\n", FILE_APPEND);
            }

            protected function onShutDown(): void
            {
                // 此时定时器应该已被 clearAllTicks 清除
                $reflection = new \ReflectionClass($this);
                $prop = $reflection->getProperty('tickIds');
                $prop->setAccessible(true);
                $ticksAtShutdown = count($prop->getValue($this));

                file_put_contents($this->arg, "TICKS_AT_SHUTDOWN:{$ticksAtShutdown}\n", FILE_APPEND);
            }
        };

        $this->swooleProcess = $testProcess->getSwooleProcess();
        $this->childPid = $this->swooleProcess->start();

        // 等待进程启动
        $this->assertTrue($this->waitForLogContent($logFile, 'STARTED', 3));

        // 等待定时器运行
        usleep(200000);

        // 触发优雅退出
        Process::kill($this->childPid, SIGTERM);

        // 验证 onShutDown 时定时器已被清除
        $ok = $this->waitForLogContent($logFile, 'TICKS_AT_SHUTDOWN:0', 5);
        $this->assertTrue(
            $ok,
            'onShutDown 执行时定时器应已被清除（clearAllTicks 在 onShutDown 之前执行）'
        );

        Process::wait();
        $this->childPid = null;
    }

    /**
     * 测试异常兜底
     * 
     * run() 中抛出异常时，onException 应被调用，进程不应崩溃
     */
    public function testExceptionHandlingInRun()
    {
        $logFile = $this->createTempFile();

        $testProcess = new class('', $logFile, false, 2, false) extends AbstractProcess {
            protected string $processName = 'exception_test';

            protected function run($arg): void
            {
                file_put_contents($this->arg, "BEFORE_EXCEPTION\n", FILE_APPEND);

                throw new \RuntimeException('Intentional run error');
            }

            protected function onException(\Throwable $throwable): void
            {
                file_put_contents($this->arg, "EXCEPTION:{$throwable->getMessage()}\n", FILE_APPEND);
            }
        };

        $this->swooleProcess = $testProcess->getSwooleProcess();
        $this->childPid = $this->swooleProcess->start();

        // 等待异常被捕获
        $ok = $this->waitForLogContent($logFile, 'EXCEPTION:Intentional run error', 3);
        $this->assertTrue($ok, 'run() 抛出异常时 onException 应被调用');

        // 验证执行顺序：先执行到异常点，再触发 onException
        $log = file_get_contents($logFile);
        $this->assertStringContainsString('BEFORE_EXCEPTION', $log);
        $this->assertStringContainsString('EXCEPTION:Intentional run error', $log);

        // 清理：进程可能已退出（run 返回后无定时器保持事件循环）
        if ($this->isProcessRunning($this->childPid)) {
            Process::kill($this->childPid, SIGTERM);
        }
        Process::wait();
        $this->childPid = null;
    }

    /**
     * 测试 onShutDown 中的清理逻辑能被执行
     * 
     * 在 onShutDown 中写入清理标记，验证优雅退出时清理逻辑确实被执行
     */
    public function testOnShutDownCleanupLogic()
    {
        $logFile = $this->createTempFile();

        $testProcess = new class('', $logFile, false, 2, false) extends AbstractProcess {
            protected string $processName = 'cleanup_test';
            protected int $maxExitWaitTime = 3;

            protected function run($arg): void
            {
                $this->addTick(500, function () {});
                file_put_contents($this->arg, "RUNNING\n", FILE_APPEND);
            }

            protected function onShutDown(): void
            {
                // 模拟清理逻辑
                file_put_contents($this->arg, "CLEANUP_START\n", FILE_APPEND);
                // 模拟耗时操作
                usleep(10000); // 10ms
                file_put_contents($this->arg, "CLEANUP_DONE\n", FILE_APPEND);
            }
        };

        $this->swooleProcess = $testProcess->getSwooleProcess();
        $this->childPid = $this->swooleProcess->start();

        $this->assertTrue($this->waitForLogContent($logFile, 'RUNNING', 3));

        usleep(100000);

        Process::kill($this->childPid, SIGTERM);

        // 验证清理逻辑完整执行
        $this->assertTrue($this->waitForLogContent($logFile, 'CLEANUP_START', 5));
        $this->assertTrue($this->waitForLogContent($logFile, 'CLEANUP_DONE', 5));

        $log = file_get_contents($logFile);
        $pos1 = strpos($log, 'CLEANUP_START');
        $pos2 = strpos($log, 'CLEANUP_DONE');
        $this->assertNotFalse($pos1);
        $this->assertNotFalse($pos2);
        $this->assertLessThan($pos2, $pos1, 'CLEANUP_START 应在 CLEANUP_DONE 之前');

        Process::wait();
        $this->childPid = null;
    }
}
