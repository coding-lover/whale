<?php

namespace App\Process;

use Sikelan\Process\AbstractProcess;

/**
 * 心跳进程
 *
 * 演示定时器和优雅退出
 */
class HeartbeatProcess extends AbstractProcess
{
    protected string $processName = 'heartbeat';

    protected function run($arg): void
    {
        $this->addTick(60000, function () {
            echo "Heartbeat: " . date('Y-m-d H:i:s') . "\n";
        });
    }

    protected function onShutDown(): void
    {
        echo "Heartbeat process shutting down gracefully\n";
    }
}