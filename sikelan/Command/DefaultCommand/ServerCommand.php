<?php

namespace Sikelan\Command\DefaultCommand;

use Sikelan\Command\CommandInterface;
use Sikelan\Framework;

class ServerCommand implements CommandInterface
{
    public function commandName(): string
    {
        return 'server';
    }

    public function exec(array $args): ?string
    {
        $action = $args[0] ?? 'start';

        switch ($action) {
            case 'start':
                return $this->start();
            case 'stop':
                return $this->stop();
            case 'restart':
                return $this->restart();
            case 'status':
                return $this->status();
            default:
                return "\033[31mUnknown action: {$action}\033[0m\n" . $this->help([]);
        }
    }

    public function help(array $args): ?string
    {
        return <<<HELP
Server Management Command

Usage:
  php sikelan server start [options]
  php sikelan server stop [options]
  php sikelan server restart [options]
  php sikelan server status

Actions:
  start    Start the server
  stop     Stop the server
  restart  Restart the server
  status   Show server status

Options:
  -e, --env=ENV   Specify environment (dev, prod, testing)
  -d              Run as daemon
  -f              Force stop (with stop command)

Examples:
  php sikelan server start
  php sikelan server start -e=dev
  php sikelan server start -d
  php sikelan server stop
  php sikelan server stop -f
  php sikelan server restart
  php sikelan server status
HELP;
    }

    public function desc(): string
    {
        return 'Server management (start, stop, restart, status)';
    }

    private function start(): string
    {
        $environment = '';
        $daemon = false;

        // 从 CommandManager 获取所有参数
        $manager = \Sikelan\Command\CommandManager::getInstance();
        $args = $manager->getArgs();

        // 遍历所有参数解析选项
        foreach ($args as $arg) {
            if ($arg === '-d' || $arg === '--daemon') {
                $daemon = true;
            } elseif (strpos($arg, '-e=') === 0) {
                $environment = substr($arg, 3);
            } elseif (strpos($arg, '--env=') === 0) {
                $environment = substr($arg, 7);
            } elseif ($arg === '-e' || $arg === '--env') {
                // 下一个参数是环境值
                $envIndex = array_search($arg, $args);
                if (isset($args[$envIndex + 1]) && !strpos($args[$envIndex + 1], '-') === 0) {
                    // 避免将 -d 等选项误认为环境值
                    $next = $args[$envIndex + 1];
                    if ($next !== '-d' && $next !== '--daemon' && $next !== '-f' && $next !== '--force') {
                        $environment = $next;
                    }
                }
            }
        }

        echo "\033[36mStarting Sikelan Framework...\033[0m\n";
        if ($environment) {
            echo "Environment: \033[32m{$environment}\033[0m\n";
        }
        if ($daemon) {
            echo "Mode: \033[32mDaemon\033[0m\n";
        }

        $app = Framework::getInstance($environment);
        $status = $app->getStatus();

        echo "\nServer Configuration:\n";
        foreach ($status as $key => $value) {
            printf("  \033[33m%-25s\033[0m %s\n", $key, $value);
        }
        echo "\n";

        if ($daemon) {
            $pidFile = $status['pid_file'] ?? (RUNTIME_PATH . '/server.pid');
            if (!is_dir(dirname($pidFile))) {
                mkdir(dirname($pidFile), 0755, true);
            }
            $pid = pcntl_fork();
            if ($pid == -1) {
                return "\033[31mFailed to fork\033[0m";
            } elseif ($pid) {
                file_put_contents($pidFile, $pid);
                return "\033[32mServer started in daemon mode (PID: {$pid})\033[0m";
            }
        }

        $app->run('http');
        return '';
    }

    private function stop(): string
    {
        $manager = \Sikelan\Command\CommandManager::getInstance();
        $args = $manager->getArgs();
        $force = in_array('-f', $args) || in_array('--force', $args);
        $pidFile = RUNTIME_PATH . '/server.pid';

        if (!file_exists($pidFile)) {
            return "\033[33mServer is not running (PID file not found)\033[0m";
        }

        $pid = (int) file_get_contents($pidFile);

        if ($pid <= 0 || !$this->processExists($pid)) {
            @unlink($pidFile);
            return "\033[33mServer process not found, cleaned up PID file\033[0m";
        }

        if ($force) {
            posix_kill($pid, SIGKILL);
            @unlink($pidFile);
            return "\033[32mServer force stopped (PID: {$pid})\033[0m";
        }

        posix_kill($pid, SIGTERM);
        @unlink($pidFile);
        return "\033[32mServer stopped (PID: {$pid})\033[0m";
    }

    /**
     * 检查进程是否存在（跨平台支持）
     */
    private function processExists(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // Linux: 通过 /proc 文件系统检查
        if (file_exists("/proc/{$pid}")) {
            return true;
        }

        // macOS/其他: 通过 ps 命令检查
        $output = [];
        @exec("ps -p {$pid} 2>/dev/null", $output);
        return count($output) > 1;
    }

    private function restart(): string
    {
        echo "\033[36mRestarting Sikelan Framework...\033[0m\n";

        $stopResult = $this->stop();
        echo $stopResult . "\n";

        sleep(1);

        return $this->start();
    }

    private function status(): string
    {
        $pidFile = RUNTIME_PATH . '/server.pid';

        echo "Server Status:\n";
        echo str_repeat('-', 50) . "\n";

        if (!file_exists($pidFile)) {
            echo "Status: \033[31mStopped\033[0m\n";
            return '';
        }

        $pid = (int) file_get_contents($pidFile);

        if ($pid > 0 && $this->processExists($pid)) {
            echo "Status: \033[32mRunning\033[0m\n";
            echo "PID: {$pid}\n";

            $app = Framework::getInstance();
            $status = $app->getStatus();
            foreach ($status as $key => $value) {
                printf("  \033[33m%-23s\033[0m %s\n", $key, $value);
            }
        } else {
            echo "Status: \033[31mStopped (stale PID file)\033[0m\n";
            @unlink($pidFile);
        }

        return '';
    }
}
