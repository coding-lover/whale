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
        // 除第一个 action 词外，剩余内容就是 options（由 CommandRunner 通过 array_slice($argv, 2) 透传，
        // 包含完整的 -e=dev / --env / -d / -f / --mode 等所有选项，没有被 CommandManager::getArgs() 过滤）
        $options = array_slice($args, 1);

        switch ($action) {
            case 'start':
                return $this->start($options);
            case 'stop':
                return $this->stop($options);
            case 'restart':
                return $this->restart($options);
            case 'status':
                return $this->status($options);
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
  -e, --env=ENV       Specify environment (dev, prod, testing); space form "-e prod" also supported
  -m, --mode=MODE     Specify server type: http | websocket | tcp (same as bin/start.php mode); "-m websocket" form supported
  -d, --daemon        Run as daemon (background)
  -f, --force         Force stop (with stop/restart commands, use SIGKILL)

Examples:
  php sikelan server start                                  # start http server with default env
  php sikelan server start -e=dev -m=http                   # http server, dev
  php sikelan server start -e prod -m websocket --daemon    # websocket server, prod, daemon
  php sikelan server stop                                   # graceful SIGTERM
  php sikelan server stop -f                                # force SIGKILL
  php sikelan server restart
  php sikelan server status
HELP;
    }

    public function desc(): string
    {
        return 'Server management (start, stop, restart, status)';
    }

    private function start(array $options): string
    {
        $environment = '';
        $daemon = false;
        $serverMode = 'http';
        $args = $options;

        // 解析选项：同时支持 "-x=value" 和 "-x value" 两种形式
        $skipNextIndex = -1;
        foreach ($args as $argIndex => $arg) {
            if ($argIndex === $skipNextIndex) {
                continue;
            }

            // 1) 守护进程 flag：-d / --daemon
            if ($arg === '-d' || $arg === '--daemon') {
                $daemon = true;
                continue;
            }

            // 2) 环境参数：-e=xxx / --env=xxx（等号形式，直接截取）
            if (strpos($arg, '-e=') === 0) {
                $environment = substr($arg, 3);
                continue;
            }
            if (strpos($arg, '--env=') === 0) {
                // "--env=" 共 6 个字符（--env=），值从索引 6 开始取（不要写成 7，会漏掉首字母）
                $environment = substr($arg, 6);
                continue;
            }

            // 3) 服务器模式：-m=xxx / --mode=xxx（等号形式）
            if (strpos($arg, '-m=') === 0) {
                $serverMode = substr($arg, 3);
                continue;
            }
            if (strpos($arg, '--mode=') === 0) {
                $serverMode = substr($arg, 7);
                continue;
            }

            // 4) 空格形式选项（-e xxx / --env xxx / -m xxx / --mode xxx）
            $isSpaceEnv  = ($arg === '-e'  || $arg === '--env');
            $isSpaceMode = ($arg === '-m'  || $arg === '--mode');
            if ($isSpaceEnv || $isSpaceMode) {
                $nextIndex = $argIndex + 1;
                if (isset($args[$nextIndex])) {
                    $nextArg = $args[$nextIndex];
                    // Bug 1 修复：!strpos===0 → strpos !== 0（下一个参数不以 '-' 开头才认为是合法值）
                    $isValue = (strpos($nextArg, '-') !== 0);
                    if ($isValue) {
                        if ($isSpaceEnv) {
                            $environment = $nextArg;
                        } else {
                            $serverMode = $nextArg;
                        }
                        // 跳过下一个索引，避免误解析成独立 flag
                        $skipNextIndex = $nextIndex;
                        continue;
                    }
                }
            }
        }

        $app = Framework::getInstance($environment);
        $status = $app->getStatus();
        // 启动标语、环境、模式与系统配置，统一由 Framework::printStatus() 输出（含 ASCII logo + 完整配置），此处避免重复

        // Bug 4 修复：启动前检查服务是否已运行，避免重复启动覆盖 pid_file 产生孤儿进程
        $pidFile = $this->getPidFile($status);
        if (file_exists($pidFile)) {
            $runningPid = (int) file_get_contents($pidFile);
            if ($runningPid > 0 && $this->processExists($runningPid)) {
                return "\033[31mServer already running (PID: {$runningPid}). Use 'server restart' or 'server stop' first.\033[0m";
            }
            // pid 文件存在但进程已死，清理陈旧文件
            @unlink($pidFile);
        }

        if ($daemon) {
            if (!is_dir(dirname($pidFile))) {
                mkdir(dirname($pidFile), 0755, true);
            }
            if (!function_exists('pcntl_fork')) {
                return "\033[31mFailed to daemonize: pcntl extension is not available\033[0m";
            }
            $pid = pcntl_fork();
            if ($pid == -1) {
                return "\033[31mFailed to fork daemon process\033[0m";
            } elseif ($pid) {
                file_put_contents($pidFile, $pid);
                return "\033[32mServer started in daemon mode (PID: {$pid}, mode: {$serverMode})\033[0m";
            }
        }

        // TCP / WebSocket 模式在未注册业务回调时会打印 WARNING 并使用默认兜底；
        // 提前在 CLI 层给用户一条可见的黄色提示，避免和真实的启动日志混淆。
        if ($serverMode === 'tcp' || $serverMode === 'websocket') {
            $event   = $serverMode === 'tcp' ? "'receive'" : "'open' + 'message'";
            $handler = $serverMode === 'tcp' ? '$server->on("receive", ...)' : '$server->on("message", ...)';
            echo "\033[33m[Notice] Running in {$serverMode} mode: ensure you register {$event} events via your "
               . "Hook::registerEvents() or {$handler}; otherwise the framework will use a built-in DEFAULT echo handler.\033[0m\n";
        }

        // Bug 3 修复：支持 -m/--mode 传参，不再写死 'http'
        $app->run($serverMode);
        return '';
    }

    private function stop(array $options): string
    {
        $args = $options;
        $force = in_array('-f', $args, true) || in_array('--force', $args, true);
        $pidFile = $this->getPidFile();

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

    /**
     * Bug 2 修复：统一的 PID 文件路径获取（start / stop / status 三入口共用，避免不一致）
     *
     * 优先级：
     *   1. 若已传入 status 数组，优先读取 status['pid_file']（Framework 配置解析后的真实值）
     *   2. 若 $status 为 null（stop/status 调用场景），通过 Framework::getInstance() 懒加载获取
     *   3. 最终兜底：RUNTIME_PATH . '/server.pid'（保证 stop/status 即便配置未定义也有统一文件名）
     *
     * 这样无论用户是否在 settings 中显式配 pid_file，三入口读写的永远是同一文件。
     */
    private function getPidFile(?array $status = null): string
    {
        if ($status === null) {
            $status = Framework::getInstance()->getStatus();
        }
        if (isset($status['pid_file']) && is_string($status['pid_file']) && $status['pid_file'] !== '') {
            return $status['pid_file'];
        }
        return RUNTIME_PATH . '/server.pid';
    }

    private function restart(array $options): string
    {
        echo "\033[36mRestarting Sikelan Framework...\033[0m\n";

        $stopResult = $this->stop($options);
        echo $stopResult . "\n";

        sleep(1);

        return $this->start($options);
    }

    private function status(array $options = []): string
    {
        $pidFile = $this->getPidFile();

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
