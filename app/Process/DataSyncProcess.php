<?php

namespace App\Process;

use Sikelan\Process\AbstractProcess;
use Swoole\Process;

/**
 * 数据同步进程
 *
 * 演示定时器和异常处理
 */
class DataSyncProcess extends AbstractProcess
{
    protected string $processName = 'data_sync';

    protected int $maxExitWaitTime = 5;

    protected function run($arg): void
    {
        // 每 300 秒执行一次数据同步
        $this->addTick(5000, function () {
            try {
                // 模拟数据同步逻辑
                echo "Data sync running at " . date('Y-m-d H:i:s') . "\n";
            } catch (\Throwable $e) {
                $this->callOnException($e);
            }
        });
    }

    protected function onShutDown(): void
    {
        echo "Data sync process shutting down, cleaning up...\n";
    }

    protected function onPipeReadable(Process $process): void
    {
        // 接收主进程发送的消息
        $msg = $process->read();
        echo "Data sync received: {$msg}\n";
    }

    protected function onException(\Throwable $throwable): void
    {
        fwrite(STDERR, "DataSync error: {$throwable->getMessage()}\n");
    }
}
