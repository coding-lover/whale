<?php

namespace Sikelan\Server;

use Sikelan\Core\Container;
use Sikelan\Core\Logger;
use Sikelan\Core\Config;
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

        $this->server->start();
    }

    /**
     * 添加自定义进程
     * 
     * 进程会在服务器启动时绑定到 Swoole Server，由 Swoole Server 管理生命周期
     * 
     * @param string $name 进程名称
     * @param callable $callback 进程回调函数
     * @param bool $redirectStdinStdout 是否重定向标准输入输出
     * @param int $pipeType 管道类型
     * @return self
     */
    public function addProcess(string $name, callable $callback, bool $redirectStdinStdout = false, int $pipeType = 2): self
    {
        $process = new \Swoole\Process(function (\Swoole\Process $worker) use ($name, $callback) {
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

        // 如果服务器已创建，直接添加到 Swoole Server
        if ($this->server) {
            $this->server->addProcess($process);
            $this->logger->debug("Process '{$name}' attached to Swoole Server");
        }

        return $this;
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
