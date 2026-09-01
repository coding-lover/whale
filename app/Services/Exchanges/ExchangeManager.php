<?php

namespace App\Services\Exchanges;

use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use App\Services\Exchanges\Adapters\BinanceExchange;
use App\Services\Exchanges\Adapters\OkxExchange;

/**
 * 交易所服务管理器
 *
 * 作为所有交易所调用的单一出口，负责：
 * - 按配置自动注册和实例化交易所适配器
 * - 管理交易所实例（懒加载，首次调用时创建）
 * - 提供默认交易所配置
 * - 支持运行时动态注册自定义交易所
 *
 * 使用方式：
 * ```php
 * // 通过框架获取管理器
 * $manager = $app->getExchange();
 *
 * // 指定交易所调用
 * $ticker = $manager->exchange('binance')->getTicker('BTC/USDT');
 * $balance = $manager->exchange('okx')->getBalance();
 *
 * // 使用默认交易所
 * $ticker = $manager->getTicker('BTC/USDT');
 * ```
 */
class ExchangeManager
{
    /**
     * 框架配置实例
     */
    protected Config $config;

    /**
     * 日志实例
     */
    protected Logger $logger;

    /**
     * 代理主机
     */
    protected string $proxyHost = '127.0.0.1';

    /**
     * 代理端口
     */
    protected int $proxyPort = 6666;

    /**
     * 是否启用代理
     */
    protected bool $proxyEnabled = true;

    /**
     * 是否启用调试日志
     */
    protected bool $debugLog = false;

    /**
     * 已注册的交易所适配器类映射
     *
     * @var array<string, string> 交易所名称 => 适配器类名
     */
    protected array $adapterClasses = [];

    /**
     * 已实例化的交易所实例缓存（懒加载）
     *
     * @var array<string, ExchangeInterface>
     */
    protected array $instances = [];

    /**
     * 实例化协程锁（惰性初始化，仅在协程环境下使用）
     *
     * @var \Swoole\Coroutine\Channel|null
     */
    protected $instanceLock = null;

    /**
     * 默认交易所名称
     */
    protected string $defaultExchange = '';

    /**
     * 系统内置的交易所适配器
     */
    protected const BUILTIN_ADAPTERS = [
        'binance' => BinanceExchange::class,
        'okx' => OkxExchange::class,
    ];

    /**
     * 构造方法
     *
     * 从配置文件读取已启用的交易所和默认交易所配置
     *
     * @param Config $config 框架配置实例
     * @param Logger $logger 日志实例
     */
    public function __construct(Config $config, Logger $logger)
    {
        $this->config = $config;
        // 使用 exchange-service 独立日志通道，日志文件保存为 exchange-service_{日期}.log
        $this->logger = $logger->withChannel('exchange-service');

        // 加载内置适配器
        $this->adapterClasses = self::BUILTIN_ADAPTERS;

        // 从配置加载自定义适配器（可选）
        $customAdapters = $config->get('exchanges.custom_adapters', []);
        foreach ($customAdapters as $name => $class) {
            $this->adapterClasses[$name] = $class;
        }

        // 设置默认交易所
        $this->defaultExchange = $config->get('exchanges.default', '');

        // 如果未配置默认交易所，使用第一个已配置 API Key 的交易所
        if ($this->defaultExchange === '') {
            $this->defaultExchange = $this->detectDefaultExchange();
        }

        // 从配置加载代理参数
        $this->proxyEnabled = (bool) $config->get('exchanges.proxy.enabled', true);
        $this->proxyHost = $config->get('exchanges.proxy.host', '127.0.0.1');
        $this->proxyPort = (int) $config->get('exchanges.proxy.port', 6666);

        // 从配置加载调试日志开关
        $this->debugLog = (bool) $config->get('exchanges.debug_log', false);
    }

    /**
     * 获取指定交易所实例
     *
     * 采用懒加载策略 + 双重检查锁定（DCL）模式，
     * 确保多协程环境下同一交易所只创建一个实例。
     * 在非协程环境下直接创建（无并发风险）。
     *
     * @param string $name 交易所名称（binance, okx 等）
     * @return ExchangeInterface
     * @throws ExchangeException 交易所未注册时抛出
     */
    public function exchange(string $name): ExchangeInterface
    {
        // 第一次检查：已实例化的直接返回（无锁快路径）
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        // 检查是否已注册适配器类
        if (!isset($this->adapterClasses[$name])) {
            throw new ExchangeException(
                "Exchange '{$name}' is not registered. "
                . "Available: " . implode(', ', array_keys($this->adapterClasses))
            );
        }

        // 检查该交易所是否配置了 API Key
        $exchangeConfig = $this->config->get('exchanges.' . $name, []);
        if (empty($exchangeConfig['api_key'])) {
            throw new ExchangeException(
                "Exchange '{$name}' has no API key configured. "
                . "Please set 'exchanges.{$name}.api_key' in config."
            );
        }

        // 协程环境下使用双重检查锁定，非协程环境直接创建
        $inCoroutine = \Swoole\Coroutine::getuid() > 0;

        if ($inCoroutine) {
            // 惰性初始化协程锁
            if ($this->instanceLock === null) {
                $this->instanceLock = new \Swoole\Coroutine\Channel(1);
                $this->instanceLock->push(true);
            }

            $this->instanceLock->pop();
            try {
                // 第二次检查：获取锁后可能已被其他协程创建
                if (isset($this->instances[$name])) {
                    return $this->instances[$name];
                }
                $this->createInstance($name, $exchangeConfig);
            } finally {
                $this->instanceLock->push(true);
            }
        } else {
            // 非协程环境：直接创建（无并发风险）
            $this->createInstance($name, $exchangeConfig);
        }

        return $this->instances[$name];
    }

    /**
     * 创建交易所适配器实例
     *
     * 创建后注入代理配置和调试日志配置
     */
    protected function createInstance(string $name, array $exchangeConfig): void
    {
        $class = $this->adapterClasses[$name];
        $instance = new $class($this->config, $this->logger);

        // 注入代理配置
        if ($instance instanceof AbstractExchange) {
            $instance->setProxy($this->proxyHost, $this->proxyPort)
                     ->enableProxy($this->proxyEnabled);

            // 注入调试日志配置
            $instance->enableDebugLog($this->debugLog);
        }

        $this->instances[$name] = $instance;

        $this->logger->info("Exchange '{$name}' instantiated", [
            'class' => $class,
            'testnet' => $exchangeConfig['testnet'] ?? false,
            'proxy' => $this->proxyEnabled ? "{$this->proxyHost}:{$this->proxyPort}" : 'disabled',
            'debug_log' => $this->debugLog ? 'enabled' : 'disabled',
        ]);
    }

    // ==================== 代理配置 ====================

    /**
     * 设置代理地址
     *
     * @param string $host 代理主机
     * @param int $port 代理端口
     * @return $this
     */
    public function setProxy(string $host, int $port): self
    {
        $this->proxyHost = $host;
        $this->proxyPort = $port;

        // 同步更新已实例化的适配器
        foreach ($this->instances as $instance) {
            if ($instance instanceof AbstractExchange) {
                $instance->setProxy($host, $port);
            }
        }

        $this->logger->info("Exchange proxy set to {$host}:{$port}");
        return $this;
    }

    /**
     * 启用代理
     *
     * @return $this
     */
    public function enableProxy(): self
    {
        $this->proxyEnabled = true;

        // 同步启用已实例化的适配器
        foreach ($this->instances as $instance) {
            if ($instance instanceof AbstractExchange) {
                $instance->enableProxy(true);
            }
        }

        $this->logger->info("Exchange proxy enabled", [
            'host' => $this->proxyHost,
            'port' => $this->proxyPort,
        ]);
        return $this;
    }

    /**
     * 禁用代理
     *
     * @return $this
     */
    public function disableProxy(): self
    {
        $this->proxyEnabled = false;

        // 同步禁用已实例化的适配器
        foreach ($this->instances as $instance) {
            if ($instance instanceof AbstractExchange) {
                $instance->enableProxy(false);
            }
        }

        $this->logger->info("Exchange proxy disabled");
        return $this;
    }

    /**
     * 检查代理是否已启用
     */
    public function isProxyEnabled(): bool
    {
        return $this->proxyEnabled;
    }

    /**
     * 获取当前代理地址
     */
    public function getProxyAddress(): string
    {
        return "{$this->proxyHost}:{$this->proxyPort}";
    }

    // ==================== 调试日志配置 ====================

    /**
     * 启用调试日志
     */
    public function enableDebugLog(): self
    {
        $this->debugLog = true;

        // 同步启用已实例化的适配器
        foreach ($this->instances as $instance) {
            if ($instance instanceof AbstractExchange) {
                $instance->enableDebugLog(true);
            }
        }

        return $this;
    }

    /**
     * 禁用调试日志
     */
    public function disableDebugLog(): self
    {
        $this->debugLog = false;

        // 同步禁用已实例化的适配器
        foreach ($this->instances as $instance) {
            if ($instance instanceof AbstractExchange) {
                $instance->enableDebugLog(false);
            }
        }

        return $this;
    }

    /**
     * 检查调试日志是否已启用
     */
    public function isDebugLogEnabled(): bool
    {
        return $this->debugLog;
    }

    /**
     * 获取默认交易所实例
     *
     * @return ExchangeInterface
     * @throws ExchangeException 未配置默认交易所时抛出
     */
    public function getDefaultExchange(): ExchangeInterface
    {
        if ($this->defaultExchange === '') {
            throw new ExchangeException(
                "No default exchange configured. "
                . "Set 'exchanges.default' in config or ensure at least one exchange has API key."
            );
        }

        return $this->exchange($this->defaultExchange);
    }

    /**
     * 获取默认交易所名称
     */
    public function getDefaultExchangeName(): string
    {
        return $this->defaultExchange;
    }

    /**
     * 动态注册自定义交易所适配器
     *
     * @param string $name 交易所名称
     * @param string $class 适配器类名（必须实现 ExchangeInterface）
     */
    public function registerAdapter(string $name, string $class): void
    {
        // 检查类是否存在
        if (!class_exists($class)) {
            throw new ExchangeException(
                "Class '{$class}' does not exist"
            );
        }

        // 检查是否实现了 ExchangeInterface 接口
        if (!in_array(ExchangeInterface::class, class_implements($class), true)) {
            throw new ExchangeException(
                "Class '{$class}' must implement ExchangeInterface"
            );
        }

        $this->adapterClasses[$name] = $class;
        $this->logger->info("Custom exchange adapter registered: {$name} => {$class}");
    }

    /**
     * 获取所有已注册的交易所名称
     *
     * @return array<string>
     */
    public function getRegisteredExchanges(): array
    {
        return array_keys($this->adapterClasses);
    }

    /**
     * 获取已配置 API Key 的交易所列表
     *
     * @return array<string>
     */
    public function getActiveExchanges(): array
    {
        $active = [];
        foreach ($this->adapterClasses as $name => $class) {
            $config = $this->config->get('exchanges.' . $name, []);
            if (!empty($config['api_key'])) {
                $active[] = $name;
            }
        }
        return $active;
    }

    // ==================== 默认交易所代理方法 ====================
    //
    // 以下方法将调用委托给默认交易所，简化使用：
    // $manager->getTicker('BTC/USDT') 等价于 $manager->exchange('binance')->getTicker('BTC/USDT')

    /**
     * 获取最新行情（通过默认交易所）
     */
    public function getTicker(string $symbol): array
    {
        return $this->getDefaultExchange()->getTicker($symbol);
    }

    /**
     * 获取深度数据（通过默认交易所）
     */
    public function getOrderBook(string $symbol, int $limit = 100): array
    {
        return $this->getDefaultExchange()->getOrderBook($symbol, $limit);
    }

    /**
     * 获取K线数据（通过默认交易所）。
     *
     * ⭐ 跨 1000 根分页：调用方传入 $startMs/$endMs 明确时间窗口；否则只返回最新 limit 根。
     */
    public function getKlines(
        string $symbol,
        string $interval,
        int $limit = 100,
        ?int $startMs = null,
        ?int $endMs = null
    ): array {
        return $this->getDefaultExchange()->getKlines($symbol, $interval, $limit, $startMs, $endMs);
    }

    /**
     * 获取最近成交记录（通过默认交易所）
     */
    public function getTrades(string $symbol, int $limit = 100): array
    {
        return $this->getDefaultExchange()->getTrades($symbol, $limit);
    }

    /**
     * 获取服务器时间（通过默认交易所）
     */
    public function getServerTime(): int
    {
        return $this->getDefaultExchange()->getServerTime();
    }

    /**
     * 获取账户余额（通过默认交易所）
     */
    public function getBalance(): array
    {
        return $this->getDefaultExchange()->getBalance();
    }

    /**
     * 创建订单（通过默认交易所）
     */
    public function createOrder(array $params): array
    {
        return $this->getDefaultExchange()->createOrder($params);
    }

    /**
     * 撤销订单（通过默认交易所）
     */
    public function cancelOrder(string $orderId, string $symbol): array
    {
        return $this->getDefaultExchange()->cancelOrder($orderId, $symbol);
    }

    /**
     * 查询订单详情（通过默认交易所）
     */
    public function getOrder(string $orderId, string $symbol): array
    {
        return $this->getDefaultExchange()->getOrder($orderId, $symbol);
    }

    /**
     * 获取当前挂单列表（通过默认交易所）
     */
    public function getOpenOrders(string $symbol = ''): array
    {
        return $this->getDefaultExchange()->getOpenOrders($symbol);
    }

    // ==================== 内部方法 ====================

    /**
     * 自动检测默认交易所
     *
     * 遍历已注册的适配器，返回第一个配置了 API Key 的交易所
     */
    protected function detectDefaultExchange(): string
    {
        foreach ($this->adapterClasses as $name => $class) {
            $config = $this->config->get('exchanges.' . $name, []);
            if (!empty($config['api_key'])) {
                return $name;
            }
        }
        return '';
    }
}
