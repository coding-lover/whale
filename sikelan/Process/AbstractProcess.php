<?php

namespace Sikelan\Process;

use Swoole\Process;
use Swoole\Coroutine\Channel;

/**
 * 自定义进程抽象基类
 * 
 * 封装了 Swoole Process 的完整生命周期管理，包括：
 * - 优雅退出：监听 SIGTERM 信号，在最大等待时间内执行清理逻辑
 * - 管道通信：主进程与子进程之间通过管道双向通信
 * - 定时器：进程内便捷添加定时任务
 * - 异常兜底：所有回调包裹在 try-catch 中，异常统一交给 onException 处理
 * 
 * 用法示例：
 * 
 * class HeartbeatProcess extends AbstractProcess
 * {
 *     protected string $processName = 'heartbeat';
 *     
 *     protected function run($arg): void
 *     {
 *         $this->addTick(60000, function () {
 *             // 每 60 秒执行一次
 *             echo "heartbeat: " . date('Y-m-d H:i:s') . "\n";
 *         });
 *     }
 *     
 *     protected function onShutDown(): void
 *     {
 *         // 进程退出时的清理逻辑
 *     }
 *     
 *     protected function onPipeReadable(Process $process): void
 *     {
 *         // 接收主进程消息
 *         $msg = $process->read();
 *         echo "Received: {$msg}\n";
 *     }
 *     
 *     protected function onException(\Throwable $throwable): void
 *     {
 *         // 异常处理
 *     }
 * }
 * 
 * // 注册到 Swoole Server
 * $server->addProcess(new HeartbeatProcess());
 */
abstract class AbstractProcess
{
    /**
     * 进程名称，用于日志标识和系统进程名
     */
    protected string $processName = '';

    /**
     * 传递给 run() 的参数
     */
    protected $arg = null;

    /**
     * 是否重定向标准输入输出
     */
    protected bool $redirectStdinStdout = false;

    /**
     * 管道类型：0=无, 1=只读, 2=读写
     */
    protected int $pipeType = 2;

    /**
     * 是否在协程中执行 run()
     */
    protected bool $enableCoroutine = true;

    /**
     * 退出最大等待时间（秒）
     * 
     * 收到 SIGTERM 信号后，onShutDown() 的最大执行时间
     * 超过此时间进程将被强制退出
     */
    protected int $maxExitWaitTime = 3;

    /**
     * Swoole Process 实例
     */
    protected ?Process $swooleProcess = null;

    /**
     * 已注册的定时器 ID 列表
     */
    protected array $tickIds = [];

    /**
     * 构造方法
     * 
     * @param string $processName 进程名称（覆盖类属性）
     * @param mixed $arg 传递给 run() 的参数
     * @param bool $redirectStdinStdout 是否重定向标准输入输出
     * @param int $pipeType 管道类型
     * @param bool $enableCoroutine 是否启用协程
     */
    public function __construct(
        string $processName = '',
        $arg = null,
        bool $redirectStdinStdout = false,
        int $pipeType = 2,
        bool $enableCoroutine = true
    ) {
        if ($processName !== '') {
            $this->processName = $processName;
        }
        $this->arg = $arg;
        $this->redirectStdinStdout = $redirectStdinStdout;
        $this->pipeType = $pipeType;
        $this->enableCoroutine = $enableCoroutine;

        // 创建 Swoole Process 实例，回调指向内部的 __start 方法
        $this->swooleProcess = new Process(
            [$this, '__start'],
            $this->redirectStdinStdout,
            $this->pipeType,
            $this->enableCoroutine
        );
    }

    /**
     * 进程启动入口（内部方法，由 Swoole 调用）
     * 
     * 完成以下工作：
     * 1. 设置进程名称（macOS 除外）
     * 2. 监听 SIGTERM 信号，实现优雅退出
     * 3. 注册管道可读事件，接收主进程消息
     * 4. 调用用户实现的 run() 方法
     */
    public function __start(Process $process): void
    {
        // 设置进程名称（macOS 不支持）
        if (PHP_OS !== 'Darwin' && $this->processName !== '') {
            $process->name($this->processName);
        }

        // 监听 SIGTERM 信号，实现优雅退出
        Process::signal(SIGTERM, function () use ($process) {
            $this->gracefulShutdown($process);
        });

        // 注册管道可读事件（主进程向子进程发消息时触发）
        if ($this->pipeType > 0) {
            \swoole_event_add($process->pipe, function () use ($process) {
                $this->callOnPipeReadable($process);
            });
        }

        // 执行用户的主逻辑
        try {
            $this->run($this->arg);
        } catch (\Throwable $throwable) {
            $this->callOnException($throwable);
        }

        // 非协程模式：手动启动事件循环（协程模式下由调度器自动管理）
        if (!$this->enableCoroutine) {
            \swoole_event_wait();
        }
    }

    /**
     * 优雅退出
     * 
     * 收到 SIGTERM 信号后：
     * 1. 移除管道事件监听
     * 2. 清除所有定时器
     * 3. 执行 onShutDown() 清理逻辑（协程模式支持超时，非协程模式直接执行）
     * 4. 退出事件循环，移除信号监听，退出进程
     */
    protected function gracefulShutdown(Process $process): void
    {
        // 移除管道事件
        if ($this->pipeType > 0) {
            \swoole_event_del($process->pipe);
        }

        // 清除所有定时器
        $this->clearAllTicks();

        if ($this->enableCoroutine) {
            // 协程模式：在协程中执行清理逻辑，支持超时强制退出
            $channel = new Channel(1);
            \go(function () use ($channel) {
                try {
                    $this->onShutDown();
                } catch (\Throwable $throwable) {
                    $this->callOnException($throwable);
                }
                $channel->push(1);
            });

            // 等待 onShutDown 完成，超时则强制退出
            $channel->pop($this->maxExitWaitTime);
        } else {
            // 非协程模式：直接执行清理逻辑
            try {
                $this->onShutDown();
            } catch (\Throwable $throwable) {
                $this->callOnException($throwable);
            }
        }

        // 退出事件循环
        \swoole_event_exit();

        // 移除信号监听
        Process::signal(SIGTERM, null);

        // 退出进程
        $process->exit(0);
    }

    /**
     * 调用 onPipeReadable，异常兜底
     */
    protected function callOnPipeReadable(Process $process): void
    {
        try {
            $this->onPipeReadable($process);
        } catch (\Throwable $throwable) {
            $this->callOnException($throwable);
        }
    }

    /**
     * 调用 onException
     * 
     * 用户可以在 onException 中自行记录日志或做其他处理
     */
    protected function callOnException(\Throwable $throwable): void
    {
        try {
            $this->onException($throwable);
        } catch (\Throwable $e) {
            // onException 自身异常时，输出到 stderr 作为最后保障
            fwrite(STDERR, "AbstractProcess onException error: {$e->getMessage()}\n");
        }
    }

    /**
     * 添加定时器（毫秒级）
     * 
     * 进程退出时会自动清除所有定时器
     * 
     * @param int $ms 间隔时间（毫秒）
     * @param callable $callback 回调函数
     * @return int 定时器 ID
     */
    public function addTick(int $ms, callable $callback): int
    {
        $tickId = \swoole_timer_tick($ms, function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $throwable) {
                $this->callOnException($throwable);
            }
        });

        $this->tickIds[] = $tickId;
        return $tickId;
    }

    /**
     * 添加一次性定时器（毫秒级，仅执行一次）
     * 
     * @param int $ms 延迟时间（毫秒）
     * @param callable $callback 回调函数
     * @return int 定时器 ID
     */
    public function addAfter(int $ms, callable $callback): int
    {
        return \swoole_timer_after($ms, function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $throwable) {
                $this->callOnException($throwable);
            }
        });
    }

    /**
     * 清除指定定时器
     */
    public function clearTick(int $tickId): void
    {
        \swoole_timer_clear($tickId);
        $this->tickIds = array_diff($this->tickIds, [$tickId]);
    }

    /**
     * 清除所有定时器
     */
    public function clearAllTicks(): void
    {
        foreach ($this->tickIds as $tickId) {
            \swoole_timer_clear($tickId);
        }
        $this->tickIds = [];
    }

    /**
     * 通过管道向主进程发送消息
     * 
     * @param string $data 消息内容
     * @return int|false 发送的字节数，失败返回 false
     */
    public function writeToMain(string $data)
    {
        return $this->swooleProcess->write($data);
    }

    /**
     * 获取 Swoole Process 实例
     */
    public function getSwooleProcess(): Process
    {
        return $this->swooleProcess;
    }

    /**
     * 获取进程名称
     */
    public function getProcessName(): string
    {
        return $this->processName;
    }

    /**
     * 获取退出最大等待时间
     */
    public function getMaxExitWaitTime(): int
    {
        return $this->maxExitWaitTime;
    }

    // ==================== 用户需实现的方法 ====================

    /**
     * 进程主逻辑（必须实现）
     * 
     * @param mixed $arg 构造时传入的参数
     */
    abstract protected function run($arg): void;

    /**
     * 进程退出时的清理逻辑（可选重写）
     */
    protected function onShutDown(): void
    {
        // 默认空实现，由子类按需重写
    }

    /**
     * 管道可读回调（可选重写）
     * 
     * 当主进程通过管道发送消息时触发
     * 
     * @param Process $process Swoole Process 实例
     */
    protected function onPipeReadable(Process $process): void
    {
        // 默认空实现，由子类按需重写
    }

    /**
     * 异常统一处理（可选重写）
     * 
     * @param \Throwable $throwable 异常对象
     */
    protected function onException(\Throwable $throwable): void
    {
        // 默认输出到 stderr
        fwrite(STDERR, "Process '{$this->processName}' error: {$throwable->getMessage()}\n");
    }
}
