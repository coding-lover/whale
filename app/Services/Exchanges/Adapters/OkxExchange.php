<?php

namespace App\Services\Exchanges\Adapters;

use App\Services\Exchanges\AbstractExchange;
use App\Services\Exchanges\ExchangeException;
use App\Services\Exchanges\Formatters\OkxSymbolFormatter;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;

/**
 * OKX 交易所适配器
 *
 * 对接 OKX v5 API，实现统一接口。
 *
 * 认证方式：HMAC-SHA256 签名，通过四个请求头传递：
 * - OK-ACCESS-KEY        API Key
 * - OK-ACCESS-SIGN       签名（timestamp + METHOD + path + body）
 * - OK-ACCESS-TIMESTAMP  ISO 8601 格式时间戳
 * - OK-ACCESS-PASSPHRASE  创建 API Key 时设置的口令
 *
 * 交易对格式：BTC-USDT（统一格式 / 替换为 -）
 * K线周期：1m→1m, 5m→5m, 1h→1H, 4h→4H, 1d→1D, 1w→1W
 * 时间戳：毫秒级整数
 *
 * @see https://www.okx.com/docs-v5/en/
 */
class OkxExchange extends AbstractExchange
{
    /**
     * 构造方法
     */
    public function __construct(Config $appConfig, Logger $logger)
    {
        parent::__construct($appConfig, $logger, new OkxSymbolFormatter());

        // OKX 默认速率限制：20 次/2秒，约每 100ms 一次
        $this->rateLimitMs = $this->config['rate_limit_ms'] ?? 100;
    }

    /**
     * 获取交易所名称
     */
    public function getName(): string
    {
        return 'okx';
    }

    // ==================== 请求构建 ====================

    /**
     * 构建 OKX 签名请求
     *
     * OKX 签名规则：
     * - 时间戳格式：ISO 8601（2025-01-01T00:00:00.000Z）
     * - 签名内容：timestamp + METHOD + path + body
     * - 签名算法：HMAC-SHA256 + Base64 编码
     * - 四个认证头：OK-ACCESS-KEY, OK-ACCESS-SIGN, OK-ACCESS-TIMESTAMP, OK-ACCESS-PASSPHRASE
     */
    protected function buildRequest(
        string $path,
        string $method,
        array $params,
        bool $signed
    ): array {
        $baseUrl = $this->getBaseUrl();
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        // GET/DELETE：参数放 query string
        // POST/PUT：参数放 JSON body
        $body = '';
        $query = '';

        if (in_array($method, ['GET', 'DELETE'])) {
            $query = http_build_query($params);
        } else {
            $body = json_encode($params, JSON_UNESCAPED_SLASHES);
        }

        $url = $baseUrl . $path;
        if ($query !== '') {
            $url .= '?' . $query;
        }

        // 公开接口不需要签名
        if (!$signed) {
            return [
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
            ];
        }

        // 私有接口需要签名
        // 签名内容：timestamp + METHOD + path(+query) + body
        $timestamp = $this->getIsoTimestamp();
        $requestPath = $path;
        if ($query !== '') {
            $requestPath .= '?' . $query;
        }

        // 拼接签名原文
        $prehash = $timestamp . strtoupper($method) . $requestPath . $body;

        // HMAC-SHA256 + Base64
        $signature = base64_encode(
            hash_hmac('sha256', $prehash, $this->getSecret(), true)
        );

        // 设置认证请求头
        $headers['OK-ACCESS-KEY'] = $this->getApiKey();
        $headers['OK-ACCESS-SIGN'] = $signature;
        $headers['OK-ACCESS-TIMESTAMP'] = $timestamp;
        $headers['OK-ACCESS-PASSPHRASE'] = $this->getPassphrase();

        return [
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * 检查 OKX API 业务错误
     *
     * OKX 错误格式：{"code":"1","msg":"...","data":[]}
     * code 为 "0" 表示成功，非 "0" 表示失败
     */
    protected function checkApiError(array $data, string $url): void
    {
        $code = (string) ($data['code'] ?? '0');

        if ($code !== '0') {
            $msg = $data['msg'] ?? 'Unknown error';
            $this->logger->error("OKX API error", [
                'url' => $url,
                'code' => $code,
                'msg' => $msg,
            ]);
            throw new ExchangeException(
                "OKX error [{$code}]: {$msg}",
                (int) $code,
                $data
            );
        }
    }

    // ==================== 格式转换 ====================

    /**
     * 统一K线周期 → OKX 原生格式
     *
     * OKX 小时及以上周期使用大写后缀
     */
    protected function formatInterval(string $interval): string
    {
        $mapping = [
            '1m' => '1m',
            '5m' => '5m',
            '15m' => '15m',
            '30m' => '30m',
            '1h' => '1H',
            '4h' => '4H',
            '1d' => '1D',
            '1w' => '1W',
        ];

        return $mapping[$interval] ?? $interval;
    }

    // ==================== 响应标准化 ====================

    /**
     * 标准化行情
     *
     * OKX 响应：{"code":"0","data":[{"instId":"BTC-USDT","last":"50000.00","ts":"1234567890000"}]}
     */
    protected function normalizeTicker(array $raw, string $symbol): array
    {
        $data = $raw['data'][0] ?? [];

        return [
            'symbol' => $symbol,
            'price' => (float) ($data['last'] ?? 0),
            'timestamp' => (int) ($data['ts'] ?? 0),
        ];
    }

    /**
     * 标准化深度
     *
     * OKX 响应：{"data":[{"asks":[["50001","2.0","0","1"],...],"bids":[...]}]}
     * OKX 深度每条有 4 个元素：[price, qty, ...]，取前两位
     */
    protected function normalizeOrderBook(array $raw): array
    {
        $data = $raw['data'][0] ?? [];

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
            'bids' => $convert($data['bids'] ?? []),
            'asks' => $convert($data['asks'] ?? []),
        ];
    }

    /**
     * 标准化K线
     *
     * OKX 响应：{"data":[["1234567890000","50000","50100","49900","50050","1.5",...], ...]}
     */
    protected function normalizeKlines(array $raw): array
    {
        $data = $raw['data'] ?? [];
        $result = [];

        foreach ($data as $kline) {
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
     * OKX 响应：{"data":[{"instId":"BTC-USDT","px":"50000","sz":"0.1","time":"123","side":"buy"}, ...]}
     */
    protected function normalizeTrades(array $raw): array
    {
        $data = $raw['data'] ?? [];
        $result = [];

        foreach ($data as $trade) {
            $result[] = [
                'id' => 0,
                'price' => (float) ($trade['px'] ?? 0),
                'qty' => (float) ($trade['sz'] ?? 0),
                'time' => (int) ($trade['ts'] ?? 0),
                'side' => strtolower($trade['side'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * 标准化余额
     *
     * OKX 响应：{"data":[{"ccy":"BTC","availBal":"1.0","frozenBal":"0.0"}, ...]}
     */
    protected function normalizeBalance(array $raw): array
    {
        $data = $raw['data'] ?? [];
        $result = [];

        foreach ($data as $item) {
            $free = (float) ($item['availBal'] ?? 0);
            $frozen = (float) ($item['frozenBal'] ?? 0);

            if ($free <= 0 && $frozen <= 0) {
                continue;
            }

            $result[$item['ccy'] ?? ''] = [
                'free' => $free,
                'used' => $frozen,
                'total' => $free + $frozen,
            ];
        }
        return $result;
    }

    /**
     * 标准化订单
     *
     * OKX 响应：{"data":[{"ordId":"123","instId":"BTC-USDT","state":"live","ordType":"limit",...}]}
     */
    protected function normalizeOrder(array $raw): array
    {
        $data = $raw['data'][0] ?? $raw;

        // OKX 订单状态映射
        $statusMap = [
            'live' => 'open',
            'partially_filled' => 'open',
            'filled' => 'filled',
            'canceled' => 'canceled',
        ];

        // OKX 订单类型映射
        $typeMap = [
            'limit' => 'limit',
            'market' => 'market',
            'post_only' => 'limit',
            'fok' => 'limit',
            'ioc' => 'limit',
        ];

        $state = $data['state'] ?? '';
        $ordType = strtolower($data['ordType'] ?? '');

        return [
            'id' => (string) ($data['ordId'] ?? ''),
            'clientOrderId' => (string) ($data['clOrdId'] ?? ''),
            'symbol' => $data['instId'] ?? '',
            'status' => $statusMap[$state] ?? strtolower($state),
            'type' => $typeMap[$ordType] ?? $ordType,
            'side' => strtolower($data['side'] ?? ''),
            'price' => (float) ($data['px'] ?? 0),
            'amount' => (float) ($data['sz'] ?? 0),
            'filled' => (float) ($data['accFillSz'] ?? 0),
            'remaining' => (float) ($data['sz'] ?? 0) - (float) ($data['accFillSz'] ?? 0),
            'timestamp' => (int) ($data['uTime'] ?? $data['cTime'] ?? 0),
        ];
    }

    // ==================== 接口实现 ====================

    public function getTicker(string $symbol): array
    {
        $raw = $this->request('/api/v5/market/ticker', 'GET', [
            'instId' => $this->formatSymbol($symbol),
        ], false);

        return $this->normalizeTicker($raw, $symbol);
    }

    public function getOrderBook(string $symbol, int $limit = 100): array
    {
        // OKX 深度档位仅支持：5, 50, 100, 500, 1000，取不超过 $limit 的最大值
        $allowedLimits = [5, 50, 100, 500, 1000];
        $depth = 5;
        foreach ($allowedLimits as $allowed) {
            if ($allowed <= $limit) {
                $depth = $allowed;
            }
        }

        $raw = $this->request('/api/v5/market/books', 'GET', [
            'instId' => $this->formatSymbol($symbol),
            'sz' => $depth,
        ], false);

        return $this->normalizeOrderBook($raw);
    }

    public function getKlines(string $symbol, string $interval, int $limit = 100): array
    {
        $raw = $this->request('/api/v5/market/candles', 'GET', [
            'instId' => $this->formatSymbol($symbol),
            'bar' => $this->formatInterval($interval),
            'limit' => $limit,
        ], false);

        return $this->normalizeKlines($raw);
    }

    public function getTrades(string $symbol, int $limit = 100): array
    {
        $raw = $this->request('/api/v5/market/trades', 'GET', [
            'instId' => $this->formatSymbol($symbol),
            'limit' => $limit,
        ], false);

        return $this->normalizeTrades($raw);
    }

    public function getServerTime(): int
    {
        $raw = $this->request('/api/v5/public/time', 'GET', [], false);
        return (int) ($raw['data'][0]['ts'] ?? 0);
    }

    public function getBalance(): array
    {
        $raw = $this->request('/api/v5/account/balance', 'GET', [], true);
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
            'instId' => $this->formatSymbol($params['symbol']),
            'tdMode' => 'cash', // 现货模式
            'side' => strtolower($params['side'] ?? 'buy'),
            'ordType' => $type,
            'sz' => (string) $params['amount'],
        ];

        // 限价单需要价格
        if ($type === 'limit') {
            $orderParams['px'] = (string) $params['price'];
        }

        // 客户自定义订单号
        if (!empty($params['clientOrderId'])) {
            $orderParams['clOrdId'] = $params['clientOrderId'];
        }

        $raw = $this->request('/api/v5/trade/order', 'POST', $orderParams, true);
        return $this->normalizeOrder($raw);
    }

    public function cancelOrder(string $orderId, string $symbol): array
    {
        $raw = $this->request('/api/v5/trade/order', 'DELETE', [
            'instId' => $this->formatSymbol($symbol),
            'ordId' => $orderId,
        ], true);

        return $this->normalizeOrder($raw);
    }

    public function getOrder(string $orderId, string $symbol): array
    {
        $raw = $this->request('/api/v5/trade/order', 'GET', [
            'instId' => $this->formatSymbol($symbol),
            'ordId' => $orderId,
        ], true);

        return $this->normalizeOrder($raw);
    }

    public function getOpenOrders(string $symbol = ''): array
    {
        $params = [];
        if ($symbol !== '') {
            $params['instId'] = $this->formatSymbol($symbol);
        }

        $raw = $this->request('/api/v5/trade/orders-pending', 'GET', $params, true);

        $result = [];
        $orders = $raw['data'] ?? [];
        foreach ($orders as $order) {
            // 包裹为 {data: [order]} 结构以复用 normalizeOrder
            $result[] = $this->normalizeOrder(['data' => [$order]]);
        }
        return $result;
    }

    // ==================== 工具方法 ====================

    /**
     * 获取 ISO 8601 格式时间戳
     *
     * OKX 要求格式：2025-01-01T00:00:00.000Z
     */
    protected function getIsoTimestamp(): string
    {
        $ms = round(microtime(true) * 1000);
        $seconds = floor($ms / 1000);
        $millis = $ms % 1000;

        return gmdate('Y-m-d\TH:i:s', (int) $seconds)
            . sprintf('.%03dZ', $millis);
    }
}
