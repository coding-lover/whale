<?php

namespace Sikelan\Crontab;

use Sikelan\Core\Container;
use Sikelan\Core\Logger;
use Swoole\Server as SwooleServer;

/**
 * 定时任务管理器
 * 
 * 基于 Swoole 定时器实现的定时任务调度器，
 * 在 workerStart 事件中启动定时任务
 */
class Crontab
{
    protected Container $container;

    protected Logger $logger;

    /**
     * 定时任务配置
     * 
     * @var array<string, array{cron: string, callback: callable, lastRun: int}>
     */
    protected array $tasks = [];

    /**
     * Swoole 定时器 ID 映射
     * 
     * @var array<string, int>
     */
    protected array $timers = [];

    /**
     * 是否已启动
     */
    protected bool $started = false;

    public function __construct(Container $container, Logger $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * 添加定时任务
     * 
     * @param string $name 任务名称
     * @param string $cronExpr cron 表达式（分钟 小时 日 月 星期）
     * @param callable $callback 任务回调
     * @return self
     */
    public function addTask(string $name, string $cronExpr, callable $callback): self
    {
        $this->tasks[$name] = [
            'cron' => $cronExpr,
            'callback' => $callback,
            'lastRun' => 0
        ];

        return $this;
    }

    /**
     * 移除定时任务
     */
    public function removeTask(string $name): self
    {
        if (isset($this->tasks[$name])) {
            unset($this->tasks[$name]);
        }
        return $this;
    }

    /**
     * onWorkerStart 事件处理
     * 
     * 在 worker 进程启动时初始化定时任务
     * 
     * @param SwooleServer $server 服务器实例
     * @param int $workerId worker 进程 ID
     */
    public function onWorkerStart(SwooleServer $server, int $workerId): void
    {
        // 仅在第一个 worker 进程启动定时任务，避免重复执行
        if ($workerId === 0 && !empty($this->tasks)) {
            $this->start();
        }
    }

    /**
     * 启动所有定时任务
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->logger->info('Crontab service started');

        foreach ($this->tasks as $name => $task) {
            $this->scheduleTask($name, $task);
        }
    }

    /**
     * 停止所有定时任务
     */
    public function stop(): void
    {
        foreach ($this->timers as $name => $timerId) {
            if ($this->started) {
                @swoole_timer_clear($timerId);
                $this->logger->info("Cron task {$name} stopped");
            }
        }

        $this->timers = [];
        $this->started = false;
        $this->logger->info('Crontab service stopped');
    }

    /**
     * 获取所有定时任务
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    /**
     * 检查是否已注册任务
     */
    public function hasTasks(): bool
    {
        return !empty($this->tasks);
    }

    /**
     * 调度单个定时任务
     */
    protected function scheduleTask(string $name, array $task): void
    {
        $nextRun = $this->getNextRunTime($task['cron']);

        if ($nextRun === false) {
            $this->logger->warning("Invalid cron expression for task {$name}");
            return;
        }

        $delay = $nextRun - time();

        if ($delay < 0) {
            $delay = 0;
        }

        $timerId = swoole_timer_after($delay * 1000, function () use ($name, $task) {
            $this->runTask($name, $task);
        });

        $this->timers[$name] = $timerId;
    }

    /**
     * 执行定时任务
     */
    protected function runTask(string $name, array $task): void
    {
        $this->logger->info("Running cron task: {$name}");

        try {
            $task['callback']();
            $this->logger->info("Cron task {$name} completed successfully");
        } catch (\Throwable $e) {
            $this->logger->error("Cron task {$name} failed: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString()
            ]);
        } finally {
            // 任务执行完成后重新调度
            $this->scheduleTask($name, $task);
        }
    }

    /**
     * 解析 cron 表达式，计算下次执行时间
     * 
     * @return int|false
     */
    protected function getNextRunTime(string $cronExpr)
    {
        $parts = preg_split('/\s+/', trim($cronExpr));

        if (count($parts) !== 5) {
            return false;
        }

        list($minute, $hour, $day, $month, $weekday) = $parts;

        $now = time();
        $current = getdate($now);

        $next = mktime(
            $this->parseField($hour, $current['hours'], 0, 23),
            $this->parseField($minute, $current['minutes'], 0, 59),
            0,
            $this->parseField($month, $current['mon'], 1, 12),
            $this->parseField($day, $current['mday'], 1, 31),
            $current['year']
        );

        if ($next <= $now) {
            $next = strtotime('+1 day', $next);
        }

        return $next;
    }

    /**
     * 解析 cron 字段
     */
    protected function parseField(string $field, int $current, int $min, int $max): int
    {
        if ($field === '*') {
            return $current;
        }

        if (strpos($field, '/') !== false) {
            list($base, $step) = explode('/', $field);
            if ($base === '*') {
                $base = $min;
            }
            $base = (int)$base;
            $step = (int)$step;

            for ($i = $base; $i <= $max; $i += $step) {
                if ($i >= $current) {
                    return $i;
                }
            }
            return $base;
        }

        if (strpos($field, '-') !== false) {
            list($start, $end) = explode('-', $field);
            $start = (int)$start;
            $end = (int)$end;

            for ($i = $start; $i <= $end; $i++) {
                if ($i >= $current) {
                    return $i;
                }
            }
            return $start;
        }

        if (strpos($field, ',') !== false) {
            $values = array_map('intval', explode(',', $field));
            foreach ($values as $value) {
                if ($value >= $current) {
                    return $value;
                }
            }
            return $values[0];
        }

        return (int)$field;
    }
}
