<?php

namespace Sikelan\Process;

use Sikelan\Core\Container;
use Sikelan\Core\Logger;
use Swoole\Process;

class ProcessManager
{
    protected $container;
    protected $logger;
    protected $processes = [];

    public function __construct(Container $container, Logger $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    public function addProcess(string $name, callable $callback, bool $redirectStdinStdout = false, int $pipeType = 2)
    {
        $process = new Process(function (Process $worker) use ($name, $callback) {
            $this->logger->info("Process {$name} started");

            try {
                $callback($worker);
            } catch (\Exception $e) {
                $this->logger->error("Process {$name} error: {$e->getMessage()}", [
                    'trace' => $e->getTraceAsString()
                ]);
            }

            $this->logger->info("Process {$name} exited");
        }, $redirectStdinStdout, $pipeType);

        $this->processes[$name] = $process;
        return $this;
    }

    public function startAll()
    {
        foreach ($this->processes as $name => $process) {
            $pid = $process->start();
            $this->logger->info("Process {$name} started with PID {$pid}");
        }

        Process::wait();
    }

    public function start(string $name)
    {
        if (!isset($this->processes[$name])) {
            throw new \InvalidArgumentException("Process {$name} not found");
        }

        $process = $this->processes[$name];
        $pid = $process->start();
        $this->logger->info("Process {$name} started with PID {$pid}");
        return $pid;
    }

    public function stop(string $name)
    {
        if (!isset($this->processes[$name])) {
            throw new \InvalidArgumentException("Process {$name} not found");
        }

        $process = $this->processes[$name];
        $process->kill();
        $this->logger->info("Process {$name} stopped");
    }

    public function stopAll()
    {
        foreach ($this->processes as $name => $process) {
            $process->kill();
            $this->logger->info("Process {$name} stopped");
        }
    }

    public function getProcess(string $name)
    {
        return $this->processes[$name] ?? null;
    }

    public function getAllProcesses()
    {
        return $this->processes;
    }
}
