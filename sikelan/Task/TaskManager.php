<?php

namespace Sikelan\Task;

use Sikelan\Core\Container;
use Sikelan\Core\Logger;
use Swoole\Server as SwooleServer;

/**
 * 任务管理器
 * 
 * 负责异步任务的创建、执行和回调处理，
 * 实现 onTask 和 onFinish 事件处理
 */
class TaskManager
{
    protected Container $container;

    protected Logger $logger;

    protected ?SwooleServer $server = null;

    public function __construct(Container $container, Logger $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * 设置 Swoole Server 实例
     */
    public function setServer(SwooleServer $server): self
    {
        $this->server = $server;
        return $this;
    }

    /**
     * 异步执行任务
     * 
     * @param string $taskClass 任务类名
     * @param array $args 任务参数
     * @param callable|null $callback 完成回调
     */
    public function async(string $taskClass, array $args = [], ?callable $callback = null): void
    {
        if (!$this->server) {
            throw new \RuntimeException('Server instance not set');
        }

        $taskData = [
            'class' => $taskClass,
            'args' => $args
        ];

        $this->server->task(json_encode($taskData), -1, function ($server, $taskId, $data) use ($callback) {
            if ($callback) {
                try {
                    $result = json_decode($data, true);
                    $callback($result);
                } catch (\Throwable $e) {
                    $this->logger->error("Task callback error: {$e->getMessage()}");
                }
            }
        });
    }

    /**
     * 同步执行任务（阻塞等待结果）
     * 
     * @param string $taskClass 任务类名
     * @param array $args 任务参数
     * @return array
     */
    public function sync(string $taskClass, array $args = []): array
    {
        if (!$this->server) {
            throw new \RuntimeException('Server instance not set');
        }

        $taskData = [
            'class' => $taskClass,
            'args' => $args
        ];

        $result = $this->server->taskwait(json_encode($taskData));

        if ($result === false) {
            return [
                'success' => false,
                'error' => 'Task execution failed or timed out'
            ];
        }

        return json_decode($result, true);
    }

    /**
     * onTask 事件处理
     * 
     * Swoole 任务进程回调，接收任务数据并执行对应任务
     * 
     * @param SwooleServer $server 服务器实例
     * @param int $taskId 任务 ID
     * @param int $workerId 工作进程 ID
     * @param string $data 任务数据（JSON 格式）
     * @return string
     */
    public function onTask(SwooleServer $server, int $taskId, int $workerId, string $data): string
    {
        try {
            $taskData = json_decode($data, true);

            if (!isset($taskData['class'])) {
                throw new \InvalidArgumentException('Task class not specified');
            }

            $taskClass = $taskData['class'];
            $args = $taskData['args'] ?? [];

            if (!class_exists($taskClass)) {
                throw new \InvalidArgumentException("Task class {$taskClass} not found");
            }

            if (!in_array(TaskInterface::class, class_implements($taskClass))) {
                throw new \InvalidArgumentException('Task class must implement TaskInterface');
            }

            // 通过容器创建任务实例并执行
            $task = $this->container->get($taskClass);
            $result = $task->handle($args);

            return json_encode([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("Task execution error: {$e->getMessage()}", [
                'task_id' => $taskId,
                'data' => $data,
                'trace' => $e->getTraceAsString()
            ]);

            return json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * onFinish 事件处理
     * 
     * 任务完成后的回调，记录任务执行结果
     * 
     * @param SwooleServer $server 服务器实例
     * @param int $taskId 任务 ID
     * @param string $data 任务结果数据
     */
    public function onFinish(SwooleServer $server, int $taskId, string $data): void
    {
        $this->logger->debug("Task #{$taskId} finished", [
            'result' => $data
        ]);
    }
}
