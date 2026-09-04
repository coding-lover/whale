<?php

namespace Sikelan\Server;

use Sikelan\Core\Container;
use Sikelan\Core\Logger;
use Sikelan\Core\Config;
use Sikelan\Process\AbstractProcess;
use Swoole\Server as SwooleServer;
use Swoole\Http\Server as HttpServer;
use Swoole\WebSocket\Server as WebSocketServer;

/**
 * 服务器组件
 * 
 * 负责 Swoole Server 的创建、配置和生命周期管理，
 * 通过 EventRegister 管理事件回调的注册与分发
 */
class Server
{
    protected ?SwooleServer $server = null;

    protected Container $container;

    protected Logger $logger;

    protected Config $config;

    protected EventRegister $eventRegister;

    /**
     * 已注册的自定义进程列表
     * 
     * 在 start() 前注册，Worker 进程 fork 后继承此数组
     */
    protected array $processes = [];

    public const TYPE_HTTP = 'http';
    public const TYPE_WEBSOCKET = 'websocket';
    public const TYPE_TCP = 'tcp';

    public function __construct(Container $container, Logger $logger, Config $config)
    {
        $this->container = $container;
        $this->logger = $logger;
        $this->config = $config;
        $this->eventRegister = new EventRegister();
    }

    /**
     * 获取事件注册器
     */
    public function getEventRegister(): EventRegister
    {
        return $this->eventRegister;
    }

    /**
     * 创建服务器实例
     */
    public function create(string $type = self::TYPE_HTTP): self
    {
        $host = $this->config->get('server.host', '0.0.0.0');
        $port = $this->config->get('server.port', 9501);

        switch ($type) {
            case self::TYPE_HTTP:
                $this->server = new HttpServer($host, $port);
                break;
            case self::TYPE_WEBSOCKET:
                $this->server = new WebSocketServer($host, $port);
                break;
            case self::TYPE_TCP:
                $this->server = new SwooleServer($host, $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
                break;
            default:
                throw new \InvalidArgumentException("Unsupported server type: {$type}");
        }

        $this->setServerConfig();
        return $this;
    }

    /**
     * 配置 Swoole Server 参数
     */
    protected function setServerConfig(): void
    {
        $settings = $this->config->get('server.settings', []);

        $defaultSettings = [
            'worker_num' => swoole_cpu_num() * 2,
            'max_request' => 10000,
            'task_worker_num' => swoole_cpu_num(),
            'enable_coroutine' => true,
            // Task 进程默认无协程上下文，任务内若要调用协程客户端（交易所 HTTP 下载等），
            // 必须开启此项；开启后 onTask 回调签名变为 ($server, Swoole\Server\Task $task)，
            // 需用 $task->finish() 返回结果（TaskManager::onTask 已兼容两种签名）。
            'task_enable_coroutine' => true,
            'open_tcp_nodelay' => true,
            'log_file' => LOG_PATH . '/swoole.log',
        ];

        $this->server->set(array_merge($defaultSettings, $settings));
    }

    /**
     * 注册事件回调到 EventRegister
     * 
     * @param string $event 事件名称
     * @param callable $callback 回调函数
     * @return self
     */
    public function on(string $event, callable $callback): self
    {
        $this->eventRegister->on($event, $callback);
        return $this;
    }

    /**
     * 启动服务器
     * 
     * 将 EventRegister 中注册的所有事件绑定到 Swoole Server 实例
     */
    public function start(): void
    {
        $this->logger->info('Server starting...', [
            'host' => $this->config->get('server.host'),
            'port' => $this->config->get('server.port')
        ]);

        // 将所有注册的事件绑定到 Swoole Server
        $this->bindEvents();

        // 绑定自定义进程到 Swoole Server
        $this->attachProcesses();

        /**
         * 启动前事件回调兜底检查（防止用户未注册必需事件导致 PHP Fatal 或运行期断连）
         *
         * Swoole 三种 Server 必需事件：
         *   1. 纯 TCP (Swoole\Server，非 Http/WebSocket 子类)：强制需要 onReceive，否则 $server->start() 直接 Fatal
         *   2. WebSocket：需要 onMessage / onOpen 才能处理客户端消息，否则接入即报错（虽不会启动 fatal）
         *   3. HTTP：on(Request) 由 Framework::registerEvents 默认注册，一般不需要兜底
         */
        $isTcp       = ($this->server instanceof SwooleServer && !$this->server instanceof HttpServer && !$this->server instanceof WebSocketServer);
        $isWebSocket = ($this->server instanceof WebSocketServer);

        // --- 兜底 1：TCP 模式必需 onReceive ---
        if ($isTcp && !$this->eventRegister->has('receive')) {
            $this->logger->warning(
                'TCP server mode: no "receive" event registered, using DEFAULT echo handler (please ' .
                'register your own callback via $server->on(\'receive\', $fn) or in your Hook::registerEvents())'
            );
            $this->server->on('receive', function (SwooleServer $server, int $fd, int $reactorId, string $data): void {
                $len = strlen($data);
                $this->logger->info('[DEFAULT TCP receive]', [
                    'fd' => $fd,
                    'reactor' => $reactorId,
                    'bytes' => $len,
                    'preview' => mb_substr($data, 0, 200),
                ]);
                // 默认回显一条提示，避免客户端一直等待
                $server->send($fd, "[Sikelan TCP Default Handler] Received {$len} bytes; register 'receive' event for business logic.\n");
            });
        }

        // --- 兜底 2：WebSocket 模式必需 onMessage / onOpen ---
        if ($isWebSocket) {
            if (!$this->eventRegister->has('message')) {
                $this->logger->warning(
                    'WebSocket server mode: no "message" event registered, using DEFAULT echo handler (please ' .
                    'register your own callback via $server->on(\'message\', $fn) or in your Hook::registerEvents())'
                );
                $this->server->on('message', function (WebSocketServer $server, \Swoole\WebSocket\Frame $frame): void {
                    $this->logger->info('[DEFAULT WebSocket message]', [
                        'fd' => $frame->fd,
                        'opcode' => $frame->opcode,
                        'bytes' => strlen($frame->data),
                    ]);
                    $server->push($frame->fd, "[Sikelan WS Default Handler] Echo: " . $frame->data);
                });
            }
            if (!$this->eventRegister->has('open')) {
                $this->logger->debug('WebSocket: no "open" event registered, using default connect ack');
                $this->server->on('open', function (WebSocketServer $server, \Swoole\Http\Request $request): void {
                    $this->logger->info('[DEFAULT WebSocket open] client connected', [
                        'fd' => $request->fd,
                        'remote_ip' => $request->header['x-forwarded-for'] ?? ($request->server['remote_addr'] ?? ''),
                    ]);
                });
            }
        }

        $this->server->start();
    }

    /**
     * 添加自定义进程
     * 
     * 支持两种方式：
     * 1. 传入 AbstractProcess 实例（推荐，支持优雅退出、管道通信、定时器、异常兜底）
     * 2. 传入 name + callback 传统方式（兼容旧用法）
     * 
     * @param AbstractProcess|string $process 进程实例或进程名称
     * @param callable|null $callback 进程回调函数（传统方式时必填）
     * @param bool $redirectStdinStdout 是否重定向标准输入输出
     * @param int $pipeType 管道类型
     * @return self
     */
    public function addProcess(
        $process,
        ?callable $callback = null,
        bool $redirectStdinStdout = false,
        int $pipeType = 2
    ): self {
        // 方式一：传入 AbstractProcess 实例（推荐）
        if ($process instanceof AbstractProcess) {
            $name = $process->getProcessName();
            $swooleProcess = $process->getSwooleProcess();

            // 保存引用，Worker 进程 fork 后可继承使用
            $this->processes[$name] = $process;

            if ($this->server) {
                $this->server->addProcess($swooleProcess);
                $this->logger->debug("Process '{$name}' attached to Swoole Server");
            }

            return $this;
        }

        // 方式二：传统 name + callback 方式（兼容）
        $name = $process;
        $swooleProcess = new \Swoole\Process(function (\Swoole\Process $worker) use ($name, $callback) {
            $this->logger->info("Custom process '{$name}' started");

            try {
                $callback($worker);
            } catch (\Throwable $e) {
                $this->logger->error("Custom process '{$name}' error: {$e->getMessage()}", [
                    'trace' => $e->getTraceAsString()
                ]);
            }

            $this->logger->info("Custom process '{$name}' exited");
        }, $redirectStdinStdout, $pipeType);

        if ($this->server) {
            $this->server->addProcess($swooleProcess);
            $this->logger->debug("Process '{$name}' attached to Swoole Server");
        }

        return $this;
    }

    /**
     * 获取已注册的自定义进程
     * 
     * Worker 进程可通过此方法获取进程实例，进而调用 sendMessage 或直接操作管道
     * 
     * @param string $name 进程名称
     * @return AbstractProcess|null
     */
    public function getProcess(string $name): ?AbstractProcess
    {
        return $this->processes[$name] ?? null;
    }

    /**
     * 向自定义进程发送消息（通过管道）
     * 
     * 在 Worker 进程中调用此方法，数据通过管道发送到目标自定义进程，
     * 目标进程的 onPipeReadable 回调会被触发
     * 
     * @param string $name 进程名称
     * @param string $data 消息内容
     * @return int|false 发送的字节数，失败返回 false
     */
    public function sendMessage(string $name, string $data)
    {
        $process = $this->processes[$name] ?? null;

        if ($process === null) {
            $this->logger->warning("Process '{$name}' not found, cannot send message");
            return false;
        }

        return $process->getSwooleProcess()->write($data);
    }

    /**
     * 绑定自定义进程到 Swoole Server
     * 
     * 在服务器启动时调用，将所有通过 Hook 注册的进程绑定到 Swoole Server
     */
    protected function attachProcesses(): void
    {
        // 进程已在 addProcess 时直接添加到 Swoole Server，此处保留用于扩展
    }

    /**
     * 将 EventRegister 中的事件绑定到 Swoole Server
     */
    protected function bindEvents(): void
    {
        $events = $this->eventRegister->all();

        foreach ($events as $event => $callbacks) {
            foreach ($callbacks as $callback) {
                $this->server->on($event, function (...$args) use ($callback, $event) {
                    try {
                        return call_user_func($callback, ...$args);
                    } catch (\Throwable $e) {
                        $this->logger->error("Event handler error: {$e->getMessage()}", [
                            'event' => $event,
                            'trace' => $e->getTraceAsString()
                        ]);

                        // task 事件需要返回错误信息，否则 taskwait() 会挂起
                        if ($event === 'task') {
                            return json_encode([
                                'success' => false,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                });
            }
        }
    }

    /**
     * 获取底层 Swoole Server 实例
     */
    public function getServer(): ?SwooleServer
    {
        return $this->server;
    }

    /**
     * 停止服务器
     */
    public function stop(): void
    {
        if ($this->server) {
            $this->server->shutdown();
        }
    }
}
