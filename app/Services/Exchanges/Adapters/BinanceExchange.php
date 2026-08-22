<?php

namespace App\Services\Exchanges\Adapters;

use App\Services\Exchanges\AbstractExchange;
use App\Services\Exchanges\ExchangeException;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;

/**
 * Binance 交易所适配器
 *
 * 对接 Binance Spot API v3，实现统一接口。
 *
 * 认证方式：HMAC-SHA256 签名，API Key 通过请求头 X-MBX-APIKEY 传递
 * 交易对格式：BTCUSDT（去掉统一格式的 / 分隔符）
 * K线周期：与统一格式一致（1m, 5m, 15m, 1h, 4h, 1d）
 * 时间戳：毫秒级整数
 *
 * @see https://developers.binance.com/docs/binance-spot-api-docs
 */
class BinanceExchange extends AbstractExchange
{
    /**
     * 构造方法
     */
    public function __construct(Config $appConfig, Logger $logger)
    {
        parent::__construct($appConfig, $logger);

        // Binance 默认速率限制：10 次/秒，即每 100ms 一次
        $this->rateLimitMs = $this->config['rate_limit_ms'] ?? 100;
    }

    /**
     * 获取交易所名称
     */
    public function getName(): string
    {
        return 'binance';
    }

    // ==================== 请求构建 ====================

    /**
     * 构建 Binance 签名请求
     *
     * Binance 签名规则：
     * - 将所有参数按字典序拼接为 query string
     * - 追加 timestamp 参数（毫秒级）
     * - 使用 HMAC-SHA256 对整个 query string 签名
     * - 签名结果作为 signature 参数追加到 query string
     * - API Key 放在请求头 X-MBX-APIKEY 中
     */
    protected function buildRequest(
        string $path,
        string $method,
        array $params,
        bool $signed
    ): array {
        $baseUrl = $this->getBaseUrl();
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];

        // 公开接口不需要签名
        if (!$signed) {
            $query = http_build_query($params);
            $url = $baseUrl . $path;
            if ($query !== '') {
                $url .= '?' . $query;
            }
            return [
                'url' => $url,
                'headers' => $headers,
                'body' => '',
            ];
        }

        // 私有接口需要签名
        // 追加时间戳和接收窗口
        $params['timestamp'] = $this->getServerTimestampMs();
        $params['recvWindow'] = $params['recvWindow'] ?? 5000;

        // 按 key 排序后拼接 query string
        ksort($params);
        $query = http_build_query($params);

        // HMAC-SHA256 签名
        $signature = hash_hmac('sha256', $query, $this->getSecret());

        // 签名追加到 query string
        $url = $baseUrl . $path . '?' . $query . '&signature=' . $signature;

        // API Key 放在请求头
        $headers['X-MBX-APIKEY'] = $this->getApiKey();

        return [
            'url' => $url,
            'headers' => $headers,
            'body' => '',
        ];
    }

    /**
     * 检查 Binance API 业务错误
     *
     * Binance 错误格式：{"code": -1121, "msg": "Invalid symbol."}
     */
    protected function checkApiError(array $data, string $url): void
    {
        // Binance 错误时返回 code 字段为负数
        if (isset($data['code']) && $data['code'] < 0) {
            $this->logger->error("Binance API error", [
                'url' => $url,
                'code' => $data['code'],
                'msg' => $data['msg'] ?? 'Unknown',
            ]);
            throw new ExchangeException(
                "Binance error [{$data['code']}]: " . ($data['msg'] ?? 'Unknown'),
                (int) $data['code'],
                $data
            );
        }
    }

    // ==================== 格式转换 ====================

    /**
     * BTC/USDT → BTCUSDT
     */
    protected function formatSymbol(string $symbol): string
    {
        return str_replace('/', '', strtoupper($symbol));
    }

    /**
     * Binance K线周期与统一格式一致，直接返回
     */
    protected function formatInterval(string $interval): string
    {
        return $interval;
    }

    // ==================== 响应标准化 ====================

    /**
     * 标准化行情
     *
     * Binance 响应：{"symbol":"BTCUSDT","price":"50000.00","time":1234567890000}
     */
    protected function normalizeTicker(array $raw, string $symbol): array
    {
        return [
            'symbol' => $symbol,
            'price' => (float) ($raw['price'] ?? 0),
            'timestamp' => (int) ($raw['time'] ?? 0),
        ];
    }

    /**
     * 标准化深度
     *
     * Binance 响应：{"bids":[["50000","1.5"],...],"asks":[["50001","2.0"],...]}
     */
    protected function normalizeOrderBook(array $raw): array
    {
        $convert = function (array $levels): array {
            $result = [];
            foreach ($levels as $level) {
                $result[] = [
                    (float) ($level[0] ?? 0), // price
                    (float) ($level[1] ?? 0), // qty
                ];
            }
            return $result;
        };

        return [
            'bids' => $convert($raw['bids'] ?? []),
            'asks' => $convert($raw['asks'] ?? []),
        ];
    }

    /**
     * 标准化K线
     *
     * Binance 响应：[[openTime, open, high, low, close, volume, closeTime, ...], ...]
     */
    protected function normalizeKlines(array $raw): array
    {
        $result = [];
        foreach ($raw as $kline) {
            $result[] = [
                (int) ($kline[0] ?? 0),   // timestamp
                (float) ($kline[1] ?? 0), // open
                (float) ($kline[2] ?? 0), // high
                (float) ($kline[3] ?? 0), // low
                (float) ($kline[4] ?? 0), // close
                (float) ($kline[5] ?? 0), // volume
            ];
        }
        return $result;
    }

    /**
     * 标准化成交
     *
     * Binance 响应：[{"id":123,"price":"50000","qty":"0.1","time":123,"isBuyerMaker":true}, ...]
     */
    protected function normalizeTrades(array $raw): array
    {
        $result = [];
        foreach ($raw as $trade) {
            $result[] = [
                'id' => (int) ($trade['id'] ?? 0),
                'price' => (float) ($trade['price'] ?? 0),
                'qty' => (float) ($trade['qty'] ?? 0),
                'time' => (int) ($trade['time'] ?? 0),
                'side' => ($trade['isBuyerMaker'] ?? false) ? 'sell' : 'buy',
            ];
        }
        return $result;
    }

    /**
     * 标准化余额
     *
     * Binance 响应：{"balances":[{"asset":"BTC","free":"1.0","locked":"0.0"}, ...]}
     */
    protected function normalizeBalance(array $raw): array
    {
        $result = [];
        $balances = $raw['balances'] ?? [];

        foreach ($balances as $item) {
            $free = (float) ($item['free'] ?? 0);
            $locked = (float) ($item['locked'] ?? 0);

            // 过滤余额为 0 的资产
            if ($free <= 0 && $locked <= 0) {
                continue;
            }

            $result[$item['asset']] = [
                'free' => $free,
                'used' => $locked,
                'total' => $free + $locked,
            ];
        }
        return $result;
    }

    /**
     * 标准化订单
     *
     * Binance 响应：{"orderId":"123","symbol":"BTCUSDT","status":"NEW","type":"LIMIT","side":"BUY",...}
     */
    protected function normalizeOrder(array $raw): array
    {
        return [
            'id' => (string) ($raw['orderId'] ?? ''),
            'clientOrderId' => (string) ($raw['clientOrderId'] ?? ''),
            'symbol' => $raw['symbol'] ?? '',
            'status' => strtolower($raw['status'] ?? ''),
            'type' => strtolower($raw['type'] ?? ''),
            'side' => strtolower($raw['side'] ?? ''),
            'price' => (float) ($raw['price'] ?? 0),
            'amount' => (float) ($raw['origQty'] ?? 0),
            'filled' => (float) ($raw['executedQty'] ?? 0),
            'remaining' => (float) ($raw['origQty'] ?? 0) - (float) ($raw['executedQty'] ?? 0),
            'timestamp' => (int) ($raw['transactTime'] ?? $raw['time'] ?? 0),
        ];
    }

    // ==================== 接口实现 ====================

    public function getTicker(string $symbol): array
    {
        $raw = $this->request('/api/v3/ticker/price', 'GET', [
            'symbol' => $this->formatSymbol($symbol),
        ], false);

        return $this->normalizeTicker($raw, $symbol);
    }

    public function getOrderBook(string $symbol, int $limit = 100): array
    {
        $raw = $this->request('/api/v3/depth', 'GET', [
            'symbol' => $this->formatSymbol($symbol),
            'limit' => $limit,
        ], false);

        return $this->normalizeOrderBook($raw);
    }

    public function getKlines(string $symbol, string $interval, int $limit = 100): array
    {
        $raw = $this->request('/api/v3/klines', 'GET', [
            'symbol' => $this->formatSymbol($symbol),
            'interval' => $this->formatInterval($interval),
            'limit' => $limit,
        ], false);

        return $this->normalizeKlines($raw);
    }

    public function getTrades(string $symbol, int $limit = 100): array
    {
        $raw = $this->request('/api/v3/trades', 'GET', [
            'symbol' => $this->formatSymbol($symbol),
            'limit' => $limit,
        ], false);

        return $this->normalizeTrades($raw);
    }

    public function getServerTime(): int
    {
        $raw = $this->request('/api/v3/time', 'GET', [], false);
        return (int) ($raw['serverTime'] ?? 0);
    }

    public function getBalance(): array
    {
        $raw = $this->request('/api/v3/account', 'GET', [], true);
        return $this->normalizeBalance($raw);
    }

    public function createOrder(array $params): array
    {
        // 校验必填参数
        if (empty($params['symbol']) || !isset($params['amount'])) {
            throw new ExchangeException(
                "createOrder requires 'symbol' and 'amount' parameters"
            );
        }

        // 限价单必须提供价格
        $type = strtolower($params['type'] ?? 'limit');
        if ($type === 'limit' && !isset($params['price'])) {
            throw new ExchangeException(
                "Limit order requires 'price' parameter"
            );
        }

        $orderParams = [
            'symbol' => $this->formatSymbol($params['symbol']),
            'side' => strtoupper($params['side'] ?? 'BUY'),
            'type' => strtoupper($type),
            'quantity' => $params['amount'],
        ];

        // 限价单需要价格
        if ($type === 'limit') {
            $orderParams['price'] = $params['price'];
            $orderParams['timeInForce'] = 'GTC';
        }

        // 客户自定义订单号
        if (!empty($params['clientOrderId'])) {
            $orderParams['newClientOrderId'] = $params['clientOrderId'];
        }

        $raw = $this->request('/api/v3/order', 'POST', $orderParams, true);
        return $this->normalizeOrder($raw);
    }

    public function cancelOrder(string $orderId, string $symbol): array
    {
        $raw = $this->request('/api/v3/order', 'DELETE', [
            'symbol' => $this->formatSymbol($symbol),
            'orderId' => $orderId,
        ], true);

        return $this->normalizeOrder($raw);
    }

    public function getOrder(string $orderId, string $symbol): array
    {
        $raw = $this->request('/api/v3/order', 'GET', [
            'symbol' => $this->formatSymbol($symbol),
            'orderId' => $orderId,
        ], true);

        return $this->normalizeOrder($raw);
    }

    public function getOpenOrders(string $symbol = ''): array
    {
        $params = [];
        if ($symbol !== '') {
            $params['symbol'] = $this->formatSymbol($symbol);
        }

        $raw = $this->request('/api/v3/openOrders', 'GET', $params, true);

        $result = [];
        foreach ($raw as $order) {
            $result[] = $this->normalizeOrder($order);
        }
        return $result;
    }

    // ==================== 工具方法 ====================

    /**
     * 获取当前毫秒级时间戳
     */
    protected function getServerTimestampMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
