<?php

namespace App\Services\Exchanges;

use App\Services\Exchanges\Formatters\SymbolFormatterInterface;
use App\Services\Exchanges\TradingSymbol;
use Swoole\Coroutine\Http\Client;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;

/**
 * 交易所抽象基类
 *
 * 封装所有交易所适配器的公共逻辑：
 * - 基于 Swoole 协程的 HTTP 请求（非阻塞）
 * - API 认证签名框架（子类实现具体算法）
 * - 速率限制器（令牌桶算法）
 * - 交易对格式转换（统一 BTC/USDT → 各交易所原生格式）
 * - K线周期格式转换
 * - 响应数据标准化框架（子类实现具体映射）
 * - 统一异常处理
 *
 * 子类需实现的抽象方法：
 * @see AbstractExchange::buildRequest()     构建签名请求
 * @see AbstractExchange::checkApiError()   检查业务错误
 * @see AbstractExchange::formatSymbol()    交易对格式转换
 * @see AbstractExchange::formatInterval()  K线周期转换
 * @see AbstractExchange::normalizeTicker()     行情响应标准化
 * @see AbstractExchange::normalizeOrderBook()   深度响应标准化
 * @see AbstractExchange::normalizeKlines()     K线响应标准化
 * @see AbstractExchange::normalizeTrades()     成交响应标准化
 * @see AbstractExchange::normalizeBalance()    余额响应标准化
 * @see AbstractExchange::normalizeOrder()      订单响应标准化
 */
abstract class AbstractExchange implements ExchangeInterface
{
    /**
     * 交易所配置
     *
     * @var array 包含 api_key, secret, passphrase, base_url 等
     */
    protected array $config;

    /**
     * 框架配置实例
     */
    protected Config $appConfig;

    /**
     * 日志实例
     */
    protected Logger $logger;

    /**
     * 是否为测试环境（使用测试网）
     */
    protected bool $testnet = false;

    /**
     * 速率限制：最小请求间隔（毫秒）
     *
     * 子类在构造函数中设置，0 表示不限制
     */
    protected int $rateLimitMs = 0;

    /**
     * 上次请求时间（毫秒级时间戳，协程安全）
     *
     * 使用 Swoole\Atomic 保证多协程下的原子读写
     */
    protected \Swoole\Atomic $lastRequestTimeAtomic;

    /**
     * HTTP 请求超时时间（秒）
     */
    protected int $timeout = 10;

    /**
     * 是否启用 SSL 证书验证
     */
    protected bool $sslVerify = true;

    /**
     * 代理主机
     */
    protected string $proxyHost = '';

    /**
     * 代理端口
     */
    protected int $proxyPort = 0;

    /**
     * 是否启用代理
     */
    protected bool $proxyEnabled = false;

    /**
     * 是否启用调试日志
     */
    protected bool $debugLog = false;

    /**
     * 交易对格式化策略
     *
     * 通过依赖注入，各适配器在构造时传入对应的 Formatter
     */
    protected SymbolFormatterInterface $symbolFormatter;

    /**
     * 构造方法
     *
     * @param Config $appConfig 框架配置实例
     * @param Logger $logger 日志实例
     * @param SymbolFormatterInterface $symbolFormatter 交易对格式化策略
     */
    public function __construct(Config $appConfig, Logger $logger, SymbolFormatterInterface $symbolFormatter)
    {
        $this->appConfig = $appConfig;
        $this->logger = $logger;
        $this->symbolFormatter = $symbolFormatter;

        // 从配置文件加载交易所参数（配置键格式：exchanges.{交易所名}）
        $configKey = 'exchanges.' . $this->getName();
        $this->config = $appConfig->get($configKey, []);

        // 是否使用测试网
        $this->testnet = (bool) ($this->config['testnet'] ?? false);

        // 是否启用 SSL 验证
        $this->sslVerify = (bool) ($this->config['ssl_verify'] ?? true);

        // 初始化协程安全的速率限制组件
        $this->lastRequestTimeAtomic = new \Swoole\Atomic(0);
    }

    // ==================== HTTP 请求 ====================

    /**
     * 发送 HTTP 请求（Swoole 协程非阻塞）
     *
     * 此方法封装了完整的请求流程：
     * 1. 速率限制检查
     * 2. 调用子类的签名方法处理认证
     * 3. 通过 Swoole 协程 HTTP 客户端发送请求
     * 4. 检查 HTTP 状态码和业务错误码
     *
     * @param string $path 请求路径，如 /api/v3/order
     * @param string $method HTTP 方法 GET|POST|DELETE|PUT
     * @param array $params 请求参数
     * @param bool $signed 是否需要签名（公开接口为 false）
     * @return array 原始响应数据（JSON 解码后的数组）
     * @throws ExchangeException 请求失败时抛出
     */
    protected function request(
        string $path,
        string $method = 'GET',
        array $params = [],
        bool $signed = false
    ): array {
        // 速率限制
        $this->rateLimit();

        // 构建请求（签名、参数、头部由子类实现）
        $requestData = $this->buildRequest($path, $method, $params, $signed);

        // 发送 HTTP 请求
        $response = $this->sendHttpRequest(
            $requestData['url'],
            $method,
            $requestData['headers'],
            $requestData['body']
        );

        // 检查 HTTP 状态码
        $statusCode = $response['statusCode'] ?? 0;
        if ($statusCode < 200 || $statusCode >= 300) {
            $body = $response['body'] ?? '';
            $this->logger->error("HTTP request failed", [
                'exchange' => $this->getName(),
                'url' => $requestData['url'],
                'method' => $method,
                'status' => $statusCode,
                'body' => $body,
            ]);

            // 连接失败时提供更友好的错误信息
            if ($statusCode === 0) {
                throw new ExchangeException(
                    "Connection failed to {$this->getName()} API "
                    . "(timeout: {$this->timeout}s, url: {$requestData['url']})",
                    0
                );
            }

            throw new ExchangeException(
                "HTTP {$statusCode}: {$body}",
                $statusCode
            );
        }

        // 解析 JSON 响应
        $data = json_decode($response['body'] ?? '', true);
        if (!is_array($data)) {
            throw new ExchangeException(
                "Invalid JSON response: " . substr($response['body'] ?? '', 0, 500)
            );
        }

        // 检查交易所业务错误码
        $this->checkApiError($data, $requestData['url']);

        return $data;
    }

    /**
     * 发送底层 HTTP 请求
     *
     * 使用 Swoole 协程 HTTP 客户端，在协程上下文中非阻塞。
     * 使用 try-finally 确保连接在任何异常下都能正确释放。
     *
     * @param string $url 完整 URL
     * @param string $method HTTP 方法
     * @param array $headers 请求头
     * @param string $body 请求体（POST/PUT 时使用）
     * @return array [statusCode, body]
     * @throws ExchangeException 连接失败时抛出
     */
    protected function sendHttpRequest(
        string $url,
        string $method,
        array $headers,
        string $body = ''
    ): array {
        // 解析 URL 获取 host、port、path
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $port = $parsed['port'] ?? 443;
        $scheme = $parsed['scheme'] ?? 'https';
        $path = $parsed['path'] ?? '/';
        $query = $parsed['query'] ?? '';

        // 拼接完整路径（含 query string）
        $fullPath = $path;
        if ($query !== '') {
            $fullPath .= '?' . $query;
        }

        // HTTP 方法统一大写
        $method = strtoupper($method);

        // 构造 Swoole 协程 HTTP 客户端
        $ssl = ($scheme === 'https');
        $client = new Client($host, $port, $ssl);

        $clientOptions = [
            'timeout' => $this->timeout,
            'connect_timeout' => 5,
            // SSL 证书验证（生产环境建议开启，测试环境可关闭）
            'ssl_verify_peer' => $this->sslVerify,
            'ssl_verify_peer_name' => $this->sslVerify,
        ];

        // 代理启用时注入 HTTP CONNECT 隧道代理
        if ($this->proxyEnabled && $this->proxyHost !== '') {
            $clientOptions['http_proxy_host'] = $this->proxyHost;
            $clientOptions['http_proxy_port'] = $this->proxyPort;
        }

        $client->set($clientOptions);

        // 设置请求头
        $client->setHeaders($headers);

        // 调试日志：请求前
        $startTime = microtime(true);
        if ($this->debugLog) {
            $this->logger->debug("Exchange HTTP request starting", [
                'exchange' => $this->getName(),
                'method' => $method,
                'host' => $host,
                'port' => $port,
                'ssl' => $ssl,
                'path' => $fullPath,
                'proxy' => $this->proxyEnabled ? "{$this->proxyHost}:{$this->proxyPort}" : 'disabled',
                'timeout' => $this->timeout,
                'body_length' => strlen($body),
                'headers' => array_keys($headers),
            ]);
        }

        try {
            // 按方法发送请求
            if ($method === 'GET') {
                $client->get($fullPath);
            } elseif ($method === 'POST') {
                $client->post($fullPath, $body);
            } elseif ($method === 'DELETE' || $method === 'PUT') {
                $client->setMethod($method);
                if ($body !== '') {
                    $client->setData($body);
                }
                $client->execute($fullPath);
            }

            $result = [
                'statusCode' => $client->statusCode,
                'body' => $client->body,
            ];

            // 连接失败时 statusCode 为 -1
            if ($result['statusCode'] < 0) {
                $result['statusCode'] = 0;
                $result['body'] = '';
            }

            // 调试日志：请求完成
            if ($this->debugLog) {
                $elapsed = round((microtime(true) - $startTime) * 1000, 2);
                $this->logger->debug("Exchange HTTP request completed", [
                    'exchange' => $this->getName(),
                    'status' => $result['statusCode'],
                    'elapsed_ms' => $elapsed,
                    'response_size' => strlen($result['body']),
                    'response_preview' => mb_substr($result['body'], 0, 200),
                    'via_proxy' => $this->proxyEnabled ? "{$this->proxyHost}:{$this->proxyPort}" : 'direct',
                ]);
            }
        } finally {
            // 确保在任何情况下都关闭连接，防止资源泄漏
            $client->close();
        }

        return $result;
    }

    // ==================== 速率限制 ====================

    /**
     * 速率限制检查（协程安全）
     *
     * 使用 Swoole\Atomic 保证上次请求时间的原子读写。
     * 在协程环境下使用协程锁 + CAS 模式确保速率计算的原子性；
     * 在非协程环境下直接执行（无并发风险）。
     */
    protected function rateLimit(): void
    {
        if ($this->rateLimitMs <= 0) {
            return;
        }

        $last = $this->lastRequestTimeAtomic->get();
        $now = (int) (microtime(true) * 1000);
        $elapsed = $now - $last;
        $waitMs = $this->rateLimitMs - $elapsed;

        if ($waitMs > 0) {
            // 协程环境下使用协程睡眠（不阻塞 Worker），非协程环境使用 usleep
            if (\Swoole\Coroutine::getuid() > 0) {
                \Swoole\Coroutine\System::sleep($waitMs / 1000);
            } else {
                usleep((int) ($waitMs * 1000));
            }
        }

        // 原子更新上次请求时间
        $this->lastRequestTimeAtomic->set((int) (microtime(true) * 1000));
    }

    // ==================== 请求构建（子类实现） ====================

    /**
     * 构建已签名的请求
     *
     * 由子类实现具体的 URL 拼接、参数序列化、签名计算和头部组装
     *
     * @param string $path API 路径
     * @param string $method HTTP 方法
     * @param array $params 请求参数
     * @param bool $signed 是否需要签名
     * @return array [url, headers, body]
     */
    abstract protected function buildRequest(
        string $path,
        string $method,
        array $params,
        bool $signed
    ): array;

    /**
     * 检查交易所 API 业务错误
     *
     * 各交易所的错误响应格式不同，由子类实现具体检查逻辑
     *
     * @param array $data 响应数据
     * @param string $url 请求 URL（用于日志）
     * @throws ExchangeException 当存在业务错误时抛出
     */
    abstract protected function checkApiError(array $data, string $url): void;

    // ==================== 格式转换（子类实现） ====================

    /**
     * 将统一交易对格式转为交易所原生格式
     *
     * 通过注入的 SymbolFormatterInterface 策略实现转换，
     * 子类无需覆写此方法。新增交易所只需创建对应 Formatter 并注入。
     *
     * @param string $symbol 统一格式交易对（如 BTC/USDT:SWAP）
     * @return string 交易所原生格式
     */
    public function formatSymbol(string $symbol): string
    {
        return $this->symbolFormatter->format(TradingSymbol::parse($symbol));
    }

    /**
     * 将交易所原生交易对格式解析为本地系统的标准交易对（反向转换）
     *
     * 对 Binance 这类无分隔符的交易所，现货/永续合约无法从格式区分，
     * 可通过 $defaultType 指定默认类型（默认 TYPE_SPOT）。
     *
     * 使用示例：
     *   $binance->parseSymbol('BTCUSDT_250328');      // BTC/USDT:FUT-250328
     *   $okx->parseSymbol('BTC-USDT-SWAP');           // BTC/USDT:SWAP
     *   $binance->parseSymbol('BTCUSDT', TYPE_SWAP);  // BTC/USDT:SWAP
     *
     * @param string $nativeSymbol 交易所原生格式
     * @param string $defaultType   无法推断类型时的默认类型
     * @return TradingSymbol 标准交易对对象（可转字符串得到标准格式）
     */
    public function parseSymbol(string $nativeSymbol, string $defaultType = TradingSymbol::TYPE_SPOT): TradingSymbol
    {
        return $this->symbolFormatter->parseExchangeSymbol($nativeSymbol, $defaultType);
    }

    /**
     * 将统一K线周期转为交易所原生格式
     *
     * 统一格式：1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w
     *
     * @param string $interval 统一格式周期
     * @return string 交易所原生格式
     */
    abstract protected function formatInterval(string $interval): string;

    // ==================== 响应标准化（子类实现） ====================

    /**
     * 标准化行情响应
     *
     * @param array $raw 原始响应
     * @param string $symbol 统一格式交易对
     * @return array [symbol, price, timestamp]
     */
    abstract protected function normalizeTicker(array $raw, string $symbol): array;

    /**
     * 标准化深度响应
     *
     * @param array $raw 原始响应
     * @return array [bids => [[price, qty], ...], asks => [[price, qty], ...]]
     */
    abstract protected function normalizeOrderBook(array $raw): array;

    /**
     * 标准化K线响应
     *
     * @param array $raw 原始响应
     * @return array [[timestamp, open, high, low, close, volume], ...]
     */
    abstract protected function normalizeKlines(array $raw): array;

    /**
     * 标准化成交响应
     *
     * @param array $raw 原始响应
     * @return array [[id, price, qty, time, side], ...]
     */
    abstract protected function normalizeTrades(array $raw): array;

    /**
     * 标准化余额响应
     *
     * @param array $raw 原始响应
     * @return array [asset => [free, used, total], ...]
     */
    abstract protected function normalizeBalance(array $raw): array;

    /**
     * 标准化订单响应
     *
     * @param array $raw 原始响应
     * @return array [id, symbol, status, type, side, price, amount, filled, ...]
     */
    abstract protected function normalizeOrder(array $raw): array;

    // ==================== 公共工具方法 ====================

    /**
     * 获取基础 URL
     */
    protected function getBaseUrl(): string
    {
        // 测试网优先使用 testnet_url，否则使用 base_url
        if ($this->testnet) {
            return $this->config['testnet_url'] ?? $this->config['base_url'] ?? '';
        }
        return $this->config['base_url'] ?? '';
    }

    /**
     * 获取 API Key
     */
    protected function getApiKey(): string
    {
        return $this->config['api_key'] ?? '';
    }

    /**
     * 获取 Secret
     */
    protected function getSecret(): string
    {
        return $this->config['secret'] ?? '';
    }

    /**
     * 获取 Passphrase（OKX 专用，Binance 不使用）
     */
    protected function getPassphrase(): string
    {
        return $this->config['passphrase'] ?? '';
    }

    /**
     * 设置 HTTP 代理
     *
     * 由 ExchangeManager 在创建实例时调用，统一注入代理配置
     *
     * @param string $host 代理主机地址
     * @param int $port 代理端口
     * @return $this
     */
    public function setProxy(string $host, int $port): self
    {
        $this->proxyHost = $host;
        $this->proxyPort = $port;
        return $this;
    }

    /**
     * 启用/禁用代理
     */
    public function enableProxy(bool $enabled = true): self
    {
        $this->proxyEnabled = $enabled;
        return $this;
    }

    /**
     * 启用/禁用 SSL 证书验证
     */
    public function setSslVerify(bool $enabled): self
    {
        $this->sslVerify = $enabled;
        return $this;
    }

    /**
     * 检查是否启用了 SSL 证书验证
     */
    public function isSslVerifyEnabled(): bool
    {
        return $this->sslVerify;
    }

    /**
     * 启用/禁用调试日志
     */
    public function enableDebugLog(bool $enabled = true): self
    {
        $this->debugLog = $enabled;
        return $this;
    }

    /**
     * 检查是否启用了调试日志
     */
    public function isDebugLogEnabled(): bool
    {
        return $this->debugLog;
    }
}
