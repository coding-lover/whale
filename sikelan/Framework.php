<?php

namespace Sikelan;

use Sikelan\Core\Container;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use Sikelan\Server\Server;
use Sikelan\Http\Router;
use Sikelan\Http\RequestHandler;
use Sikelan\Task\TaskManager;
use Sikelan\Crontab\Crontab;
use Sikelan\Cache\RedisCache;
use Sikelan\Database\MysqlPool;
use Sikelan\Process\ProcessManager;
use Sikelan\Process\AbstractProcess;
use Sikelan\Hook\HookInterface;
use App\Services\Exchanges\ExchangeManager;

/**
 * 框架总指挥类
 * 
 * 负责框架的生命周期编排和组件管理，
 * 使用单例模式确保全局只有一个实例，
 * 通过组件间接管理 Swoole，不直接操作 Swoole 实例
 */
class Framework
{
    protected Container $container;

    protected Config $config;

    protected Logger $logger;

    protected ?Server $server = null;

    protected Router $router;

    protected RequestHandler $requestHandler;

    protected TaskManager $taskManager;

    protected Crontab $crontab;

    protected ?RedisCache $cache = null;

    protected ?MysqlPool $db = null;

    protected ?ProcessManager $processManager = null;

    protected ?ExchangeManager $exchangeManager = null;

    protected ?HookInterface $hook = null;

    protected string $environment = 'development';

    protected int $startTime = 0;

    private static ?self $_instance = null;

    /**
     * 获取框架单例
     */
    public static function getInstance(string $environment = ''): self
    {
        if (self::$_instance === null) {
            self::$_instance = new self($environment);
        }
        return self::$_instance;
    }

    /**
     * 初始化框架
     */
    private function __construct(string $environment = '')
    {
        $this->loadConstants();

        $this->environment = $this->resolveEnvironment($environment);

        // 初始化容器和核心组件
        $this->initCoreComponents();

        // 初始化事件处理组件（先初始化，后面加载路由需要用到 router）
        $this->initEventComponents();

        // 加载路由
        $this->loadRoutes();

        // 加载 Hook（如果配置了的话）
        $this->initHook();
    }

    /**
     * 初始化核心组件
     */
    protected function initCoreComponents(): void
    {
        $this->container = new Container();
        $this->config = new Config('', $this->environment);
        $this->config->set('app.env', $this->environment);
        $this->container->set('config', $this->config);
        $this->container->set(Config::class, $this->config);

        $this->logger = new Logger($this->config);
        $this->container->set(Logger::class, $this->logger);
    }

    /**
     * 初始化事件处理组件
     */
    protected function initEventComponents(): void
    {
        // 路由
        $this->router = $this->container->get(Router::class);

        // 请求处理器
        $this->requestHandler = new RequestHandler(
            $this->container,
            $this->logger,
            $this->router
        );

        // 任务管理器
        $this->taskManager = $this->container->get(TaskManager::class);

        // 定时任务管理器
        $this->crontab = $this->container->get(Crontab::class);
    }

    /**
     * 加载常量和公共函数
     */
    protected function loadConstants(): void
    {
        require __DIR__ . '/Core/constants.php';
        require __DIR__ . '/Core/common.php';
        // 加载应用层公共函数（如 app()、exchange()、cache() 等快捷函数）
        require APP_PATH . '/common.php';
    }

    /**
     * 加载路由配置
     */
    protected function loadRoutes(): void
    {
        $routerFile = CONFIG_PATH . '/router.php';

        if (file_exists($routerFile)) {
            $this->router->loadFromFile($routerFile);
        }
    }

    /**
     * 初始化 Hook
     * 
     * 如果配置了 app.hook，则实例化用户自定义的 Hook 类
     */
    protected function initHook(): void
    {
        $hookClass = $this->config->get('app.hook');

        if ($hookClass && class_exists($hookClass)) {
            $this->hook = new $hookClass($this->container, $this->config, $this->logger);
            $this->logger->info("Hook loaded: {$hookClass}");
        }
    }

    /**
     * 解析运行环境
     */
    protected function resolveEnvironment(string $environment): string
    {
        if ($environment !== '') {
            return $environment;
        }

        $envFromFile = env('APP_ENV', '');
        if ($envFromFile !== '') {
            return $envFromFile;
        }

        return 'development';
    }

    /**
     * 启动框架
     * 
     * @param string $mode 服务器类型（http, websocket, tcp）
     */
    public function run(string $mode = 'http'): void
    {
        // 创建服务器实例
        $this->server = $this->container->get(Server::class)->create($mode);
        $this->startTime = time();

        // 打印框架状态
        $this->printStatus();
        $this->logger->info("Starting Sikelan framework in {$mode} mode");

        // 如果配置了 Hook，调用初始化钩子
        if ($this->hook) {
            $this->hook->onInitialize($this->server);
        }

        // 注册事件回调到 Server 组件
        $this->registerEvents();

        // 绑定 Server 到任务管理器
        $this->taskManager->setServer($this->server->getServer());

        // 注册自定义进程（通过 Hook）
        $this->registerProcesses();

        // 如果配置了 Hook，调用启动前钩子
        if ($this->hook) {
            $this->hook->onServerStart($this->server);
        }

        // 启动服务器
        $this->server->start();
    }

    /**
     * 注册事件回调
     * 
     * 先注册框架默认的事件回调，如果配置了 Hook 则用 Hook 返回的事件覆盖同名回调
     */
    protected function registerEvents(): void
    {
        // === 注册框架默认的事件回调 ===

        // HTTP 请求处理
        $this->server->on('request', [$this->requestHandler, 'handle']);

        // 任务处理
        $this->server->on('task', [$this->taskManager, 'onTask']);
        $this->server->on('finish', [$this->taskManager, 'onFinish']);

        // Worker 启动处理（用于定时任务等）
        $this->server->on('workerStart', [$this->crontab, 'onWorkerStart']);

        // === 如果配置了 Hook，用用户自定义的事件回调覆盖默认回调 ===
        if ($this->hook) {
            $customEvents = $this->hook->registerEvents();

            foreach ($customEvents as $event => $callback) {
                // 使用 set() 覆盖默认的事件回调
                $this->server->getEventRegister()->set($event, $callback);
                $this->logger->info("Event '{$event}' overridden by hook");
            }
        }
    }

    /**
     * 注册自定义进程
     * 
     * 通过 Hook 注册的自定义进程会绑定到 Swoole Server，由 Swoole Server 管理生命周期
     * 支持两种返回格式：
     * - AbstractProcess 实例（推荐，支持优雅退出、管道通信、定时器、异常兜底）
     * - 数组配置 ['name' => ..., 'callback' => ...]（兼容旧用法）
     */
    protected function registerProcesses(): void
    {
        if (!$this->hook) {
            return;
        }

        $processes = $this->hook->registerProcesses();

        foreach ($processes as $processConfig) {
            // 方式一：AbstractProcess 实例（推荐）
            if ($processConfig instanceof AbstractProcess) {
                $this->server->addProcess($processConfig);
                $this->logger->info("Custom process '{$processConfig->getProcessName()}' registered via hook");
                continue;
            }

            // 方式二：数组配置（兼容旧用法）
            $name = $processConfig['name'] ?? 'unnamed';
            $callback = $processConfig['callback'];
            $redirectStdinStdout = $processConfig['redirectStdinStdout'] ?? false;
            $pipeType = $processConfig['pipeType'] ?? 2;

            $this->server->addProcess($name, $callback, $redirectStdinStdout, $pipeType);
            $this->logger->info("Custom process '{$name}' registered via hook");
        }
    }

    /**
     * 停止框架
     */
    public function stop(): void
    {
        if ($this->server) {
            $this->server->stop();
        }
    }

    /**
     * 获取运行环境
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * 获取容器
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * 获取路由
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * 获取任务管理器
     */
    public function getTaskManager(): TaskManager
    {
        return $this->taskManager;
    }

    /**
     * 获取定时任务管理器
     */
    public function getCrontab(): Crontab
    {
        return $this->crontab;
    }

    /**
     * 获取服务器组件
     */
    public function getServer(): ?Server
    {
        return $this->server;
    }

    /**
     * 获取日志
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }

    /**
     * 获取配置
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * 获取缓存实例
     */
    public function getCache(): RedisCache
    {
        if ($this->cache === null) {
            $this->cache = $this->container->get(RedisCache::class);
        }
        return $this->cache;
    }

    /**
     * 获取数据库连接池
     */
    public function getDb(): MysqlPool
    {
        if ($this->db === null) {
            $this->db = $this->container->get(MysqlPool::class);
        }
        return $this->db;
    }

    /**
     * 获取进程管理器
     */
    public function getProcessManager(): ProcessManager
    {
        if ($this->processManager === null) {
            $this->processManager = $this->container->get(ProcessManager::class);
        }
        return $this->processManager;
    }

    /**
     * 获取 Hook 实例
     */
    public function getHook(): ?HookInterface
    {
        return $this->hook;
    }

    /**
     * 获取交易所服务管理器
     *
     * 作为所有交易所调用的单一出口，支持多交易所
     *
     * 用法：
     * ```php
     * $exchange = $app->getExchange();
     *
     * // 使用默认交易所
     * $ticker = $exchange->getTicker('BTC/USDT');
     *
     * // 指定交易所
     * $balance = $exchange->exchange('okx')->getBalance();
     * ```
     */
    public function getExchange(): ExchangeManager
    {
        if ($this->exchangeManager === null) {
            $this->exchangeManager = $this->container->get(ExchangeManager::class);
        }
        return $this->exchangeManager;
    }

    /**
     * 获取框架状态信息
     */
    public function getStatus(): array
    {
        $serverType = $this->server ? get_class($this->server->getServer()) : 'Not started';

        $serverConfig = $this->config->get('server', []);
        $settings = $serverConfig['settings'] ?? [];

        // 获取 Swoole 运行时统计信息
        $serverStats = [];
        if ($this->server && $this->server->getServer()) {
            $stats = @$this->server->getServer()->stats();
            if ($stats !== false && is_array($stats)) {
                $serverStats = $stats;
            }
        }

        return [
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'uptime' => $this->startTime > 0 ? time() - $this->startTime : 0,
            'uptime_human' => $this->formatUptime($this->startTime > 0 ? time() - $this->startTime : 0),
            'main_server' => $serverType,
            'listen_address' => $serverConfig['host'] ?? '0.0.0.0',
            'listen_port' => $serverConfig['port'] ?? 9501,
            'worker_num' => $settings['worker_num'] ?? swoole_cpu_num() * 2,
            'reload_async' => $settings['reload_async'] ?? 1,
            'max_wait_time' => $settings['max_wait_time'] ?? 3,
            'enable_static_handler' => $settings['enable_static_handler'] ?? 1,
            'max_request' => $settings['max_request'] ?? 10000,
            'pid_file' => $settings['pid_file'] ?? RUNTIME_PATH . '/pid.pid',
            'log_file' => $settings['log_file'] ?? LOG_PATH . '/swoole.log',
            'run_at_user' => posix_getpwuid(posix_geteuid())['name'] ?? 'unknown',
            'daemonize' => $settings['daemonize'] ?? false,
            'swoole_version' => SWOOLE_VERSION,
            'php_version' => PHP_VERSION,
            'framework_version' => '1.0.0',
            'environment' => $this->environment,
            'temp_dir' => $settings['temp_dir'] ?? RUNTIME_PATH,
            'log_dir' => $this->config->get('app.log_path', LOG_PATH),
            'memory' => [
                'usage' => memory_get_usage(true),
                'usage_human' => $this->formatBytes(memory_get_usage(true)),
                'peak' => memory_get_peak_usage(true),
                'peak_human' => $this->formatBytes(memory_get_peak_usage(true)),
            ],
            'server_stats' => $serverStats,
            'routes_count' => count($this->router->getRoutes()),
        ];
    }

    /**
     * 格式化运行时长
     */
    protected function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days}d";
        }
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }
        $parts[] = "{$secs}s";

        return implode(' ', $parts);
    }

    /**
     * 格式化字节大小
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * 打印框架状态
     */
    public function printStatus(): void
    {
        $status = $this->getStatus();

        $logo = <<<'LOGO'
 ____ ___ _  _______ _        _    _   _ 
/ ___|_ _| |/ / ____| |      / \  | \ | |
\___ \| || ' /|  _| | |     / _ \ |  \| |
 ___) | || . \| |___| |___ / ___ \| |\  |
|____/___|_|\_\_____|_____/_/   \_\_| \_|
                                        
LOGO;

        echo "\033[36m{$logo}\033[0m\n\n";

        $this->printStatusLine('', $status);

        echo "\n";
    }

    /**
     * 打印状态行
     */
    protected function printStatusLine(string $prefix, $data): void
    {
        foreach ($data as $key => $value) {
            $label = $prefix === '' 
                ? str_replace('_', ' ', ucwords((string)$key)) 
                : str_replace('_', ' ', (string)$key);

            if (is_array($value)) {
                printf("\033[32m%-25s\033[0m %s\n", $label, '');
                $this->printStatusLine($prefix . '  ', $value);
            } else {
                $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                printf("\033[32m%-25s\033[0m %s\n", $prefix . $label, $displayValue);
            }
        }
    }
}
