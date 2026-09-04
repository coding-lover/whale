<?php

namespace App\Services\Exchanges\Adapters;

use App\Services\Exchanges\AbstractExchange;
use App\Services\Exchanges\ExchangeException;
use App\Services\Exchanges\Formatters\BinanceSymbolFormatter;
use App\Services\Exchanges\TradingSymbol;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use Sikelan\Core\Traits\PerCidContextTrait;

/**
 * Binance 交易所适配器 — 统一兼容 3 个市场：现货 / U本位(USDⓈ-M) / 币本位(COIN-M)。
 *
 * Bug 修复（原始 BinanceExchange）：之前 hard-coded 使用 Spot 的 `api.binance.com` + `/api/v3/*` 路径，
 *   当传入 BTC/USDT:SWAP / BTCUSD:QUARTER 时仍请求现货域名/路径，404 或 "Invalid symbol"。
 *
 * 路由规则（根据 TradingSymbol 的 type / quote）：
 *   ┌────────────────────┬───────────────────┬───────────────────┬─────────────────────────────┐
 *   │ 市场               │ TradingSymbol.type │ quote           │ Binance 原生 REST           │
 *   ├────────────────────┼───────────────────┼───────────────────┼─────────────────────────────┤
 *   │ SPOT 现货           │ TYPE_SPOT 或缺省    │ 任意              │ api.binance.com  /api/v3/* │
 *   │ USD-M 永续/交割(U本位)│ TYPE_SWAP         │ 非 USD（USDT/BUSD…）│ fapi.binance.com /fapi/v1/* │
 *   │ USD-M 交割(U本位)    │ TYPE_FUTURES      │ 非 USD            │ fapi.binance.com /fapi/v1/* │
 *   │ COIN-M 永续(币本位)  │ TYPE_SWAP         │ USD              │ dapi.binance.com /dapi/v1/* │
 *   │ COIN-M 交割(币本位)  │ TYPE_FUTURES      │ USD              │ dapi.binance.com /dapi/v1/* │
 *   └────────────────────┴───────────────────┴───────────────────┴─────────────────────────────┘
 *
 * 差异点（文档里列出的都已对齐）：
 *   · REST 域名（base_url）不同。
 *   · URL 前缀不同（Spot=/api/v3、USD-M=/fapi/v1、COIN-M=/dapi/v1）。
 *   · 错误字段：Spot 的 Ticker 使用 `price/time` 顶层字段；USD-M/COIN-M `/fapi/v1/ticker/price` 顶层是 {symbol,price,...}
 *     但时间字段叫 `time`；COIN-M 深度 `depth` 返回 `T`/`E` 作为时间戳。
 *   · 订单：Spot 叫 `origQty`；Futures 叫 `origQty`（一致），但余额/持仓 Futures 用 `assets` 数组与 `positions`。
 *   · COIN-M 订单用 `pair` 标识产品，Spot/USD-M 用 `symbol`。
 *   · Account：Spot `/api/v3/account` 返回 `balances[].{asset,free,locked}`；
 *     USD-M `/fapi/v2/balance` 返回 [{accountAlias,asset,balance,...}]；
 *     COIN-M `/dapi/v1/balance` 返回 [{accountAlias,asset,balance,...}]。
 *   · 错误响应：Spot/Futures 都用 {code,msg}；USD-M 的 code 可能为负或正（如 400）。
 *
 * 配置来源：config/exchanges.php 的 `binance.markets.{spot|usd_m|coin_m}`，
 *   顶层字段（base_url/api_key 等）视为 SPOT 默认 + 其他两市场 fallback。
 *
 * 向后兼容：对于 BTC/USDT 这种「无 TYPE」的纯现货符号，行为和老适配器 100% 一致。
 *
 * @see https://developers.binance.com/en/docs/products/spot/rest-api                  Spot
 * @see https://developers.binance.com/en/docs/products/derivatives-trading-usds-futures/general-info   USDⓈ-M
 * @see https://developers.binance.com/en/docs/products/derivatives-trading-coin-futures/general-info   COIN-M
 */
class BinanceExchange extends AbstractExchange
{
    // ⭐ 按 cid 隔离上下文 + 对象属性 swap/还原。
    //   并发协程调用不同 symbol 接口时，各协程的 market config/sslVerify/testnet 绝不会串。
    use PerCidContextTrait;

    /** scope key（传给 PerCidContextTrait 的业务隔离键） */
    private const SCOPE_MARKET = 'binance_market';

    // ----------------------------------------------------------------
    //  3 个市场标识常量（resolveMarket 返回值）
    // ----------------------------------------------------------------

    public const MARKET_SPOT   = 'spot';
    public const MARKET_USD_M  = 'usd_m';
    public const MARKET_COIN_M = 'coin_m';

    // ----------------------------------------------------------------
    //  协程安全的服务器时间戳缓存（替代原来的 static $cachedDelta）
    // ----------------------------------------------------------------

    /** 本地时钟与服务器时钟的毫秒偏移量（所有协程共享同一份缓存值） */
    protected ?int $serverTimeDelta = null;

    /**
     * 一次性初始化标记（0=未初始化 / 1=初始化中 / 2=已完成）。
     * 用 Swoole\Atomic + cmpset(CAS) 保证多协程并发首次调用时，
     * 只有一个协程发起 /time HTTP 请求，其余协程等待结果复用。
     */
    protected \Swoole\Atomic $serverTimeInitAtomic;

    /** 3 个市场默认的 path 前缀 + 默认 base_url / testnet_url 常量（文档 3 个链接中的标准值，即使 config 丢了也能跑）*/
    protected const MARKET_DEFAULTS = [
        self::MARKET_SPOT => [
            'path_prefix' => '/api/v3',
            'base_url'    => 'https://api.binance.com',
            'testnet_url' => 'https://testnet.binance.vision',
        ],
        self::MARKET_USD_M => [
            'path_prefix' => '/fapi/v1',
            'base_url'    => 'https://fapi.binance.com',
            'testnet_url' => 'https://demo-fapi.binance.com',
        ],
        self::MARKET_COIN_M => [
            'path_prefix' => '/dapi/v1',
            'base_url'    => 'https://dapi.binance.com',
            'testnet_url' => 'https://demo-dapi.binance.com',
        ],
    ];

    // ----------------------------------------------------------------
    //  构造
    // ----------------------------------------------------------------

    public function __construct(Config $appConfig, Logger $logger)
    {
        parent::__construct($appConfig, $logger, new BinanceSymbolFormatter());

        // Binance 默认速率限制：10 次/秒；Futures 略宽松（20/s），这里取最严的值统一兜底。
        $this->rateLimitMs = $this->config['rate_limit_ms'] ?? 100;

        // 协程安全的 server time delta 一次性初始化标记（见 getServerTimestampMs）
        $this->serverTimeInitAtomic = new \Swoole\Atomic(0);
    }

    public function getName(): string
    {
        return 'binance';
    }

    // =================================================================
    //  ① 市场判定 + 配置合并 + path / baseUrl / 凭证动态注入
    // =================================================================

    /**
     * 取某个市场的最终合并配置（含继承链）。
     *
     * 合并优先级（高 → 低）：
     *   - binance.markets.{market}.xxx
     *   - binance.xxx                  （SPOT 顶层 = 旧单市场配置，作为其他两市场 fallback）
     *   - MARKET_DEFAULTS[market].xxx  （文档标准默认值，保证 config 被清空也能跑）
     *
     * 返回数组结构：{
     *   market, path_prefix,
     *   base_url, testnet, testnet_url,
     *   api_key, secret, ssl_verify
     * }
     *
     * @param string $market self::MARKET_*
     */
    protected function getMarketConfig(string $market): array
    {
        $defaults = self::MARKET_DEFAULTS[$market];
        $top      = $this->config;
        $marketCfg = $this->config['markets'][$market] ?? [];

        // 字段按优先级挑第一个非 null/非空
        $pick = static function (string $key, $fallback) use ($marketCfg, $top, $defaults) {
            // 1) 市场里显式写了（哪怕是 ''/false 都算显式）
            if (array_key_exists($key, $marketCfg) && $marketCfg[$key] !== null) {
                return $marketCfg[$key];
            }
            // 2) 顶层有这 key 且非空
            if (array_key_exists($key, $top) && $top[$key] !== null && $top[$key] !== '') {
                return $top[$key];
            }
            // 3) MARKET_DEFAULTS
            if (array_key_exists($key, $defaults) && $defaults[$key] !== null && $defaults[$key] !== '') {
                return $defaults[$key];
            }
            return $fallback;
        };

        return [
            'market'      => $market,
            'path_prefix' => $marketCfg['path_prefix'] ?? $defaults['path_prefix'],
            'base_url'    => $pick('base_url', $defaults['base_url']),
            'testnet'     => array_key_exists('testnet', $marketCfg) && $marketCfg['testnet'] !== null
                ? (bool) $marketCfg['testnet']
                : (bool) ($top['testnet'] ?? false),
            'testnet_url' => $pick('testnet_url', $defaults['testnet_url']),
            'api_key'     => $pick('api_key', ''),
            'secret'      => $pick('secret', ''),
            'ssl_verify'  => array_key_exists('ssl_verify', $marketCfg) && $marketCfg['ssl_verify'] !== null
                ? (bool) $marketCfg['ssl_verify']
                : (bool) ($top['ssl_verify'] ?? true),
        ];
    }

    /**
     * 根据标准交易对字符串 → 决定「SPOT / USD-M / COIN-M」市场。
     *
     * 规则对齐 TradingSymbol 标准：
     *   · 默认（只传 BTCUSDT 字符串；defaultType=spot）→ MARKET_SPOT
     *   · TYPE_SWAP
     *       - quote==='USD'            → COIN-M 永续
     *       - 其他（USDT/BUSD…）      → USD-M 永续
     *   · TYPE_FUTURES
     *       - quote==='USD'            → COIN-M 交割（THIS_WEEK/NEXT_WEEK/QUARTER/CI_QUARTER）
     *       - 其他（USDT/具体 YYMMDD）→ USD-M 交割
     *
     * @param string $symbol  标准交易对字符串（TradingSymbol 可解析）
     * @return array{0:string, 1:TradingSymbol}  [market, parsedSymbol]
     */
    protected function resolveMarket(string $symbol): array
    {
        // 默认解析为现货（跟 BinanceSymbolFormatter::parse() defaultType=spot 行为一致）
        try {
            $parsed = TradingSymbol::parse($symbol, TradingSymbol::TYPE_SPOT);
        } catch (\Throwable $e) {
            // 任何 parse 异常，兜底按现货处理，后面再让 API 返回"Invalid symbol"，避免这里先炸
            return [self::MARKET_SPOT, new TradingSymbol($symbol, 'USDT', TradingSymbol::TYPE_SPOT)];
        }
        $type  = $parsed->getType();
        $quote = $parsed->getQuote();

        if ($type === TradingSymbol::TYPE_SPOT) {
            return [self::MARKET_SPOT, $parsed];
        }
        // 非 SPOT → 看 quote 决定 USD-M vs COIN-M（COIN-M 本位都是 USD 或者 BTC，这里用 USD 作为分水岭；
        //   BinanceSymbolFormatter 里 quote='USD' → 格式为 BTCUSD_PERP → 对应 dapi.binance.com）
        if ($quote === 'USD') {
            return [self::MARKET_COIN_M, $parsed];
        }
        return [self::MARKET_USD_M, $parsed];
    }

    // =================================================================
    //  ② 请求构建：buildRequest / checkApiError 动态按市场拼接前缀
    // =================================================================

    /**
     * 在某个市场上下文里执行一次 $callback；callback 内
     *   getBaseUrl()/getApiKey()/getSecret()/buildRequest 都会用该市场配置。
     *
     * 🏛 协程安全实现（完全交给 PerCidContextTrait，本方法只保留纯业务逻辑）：
     *   · 「解析 symbol → 决定 market + 拼 cfg」这 3 步是业务代码，保留在这里；
     *   · 「cid 隔离上下文栈 + sslVerify/testnet 对象属性 swap/还原 + finally 弹栈」
     *     这些**和 Binance API 无关**的并发基础设施，已抽离到框架层的
     *     PerCidContextTrait::runInScopedContext()，本方法只 1 行透传调用。
     *
     * @template T
     * @param TradingSymbol|string $symbolOrMarket  传 TradingSymbol/字符串符号（自动解析市场）或直接传 MARKET_* 常量
     * @param callable(array $cfg):T $fn  回调参数 = 当前市场的完整 config 数组（{market,path_prefix,base_url,...}）
     * @return T
     */
    protected function withMarketContext($symbolOrMarket, callable $fn)
    {
        // ① 业务代码：决定 market 并拼出这个 market 的完整 config（纯 PHP，无协程副作用）
        if ($symbolOrMarket instanceof TradingSymbol) {
            $market = $this->marketFromSymbol($symbolOrMarket);
        } elseif (is_string($symbolOrMarket) && in_array($symbolOrMarket, [self::MARKET_SPOT, self::MARKET_USD_M, self::MARKET_COIN_M], true)) {
            $market = $symbolOrMarket;
        } elseif (is_string($symbolOrMarket)) {
            [$market, ] = $this->resolveMarket($symbolOrMarket);
        } else {
            $market = self::MARKET_SPOT;
        }
        $cfg = $this->getMarketConfig($market);

        $payload = ['market' => $market, 'config' => $cfg];

        // ② 1 行交给 Trait：cid 隔离上下文 + 对象属性 swap/还原
        //    · payload 会被 Trait 以 scope=SCOPE_MARKET + 当前 cid 为 key 存进私有栈
        //    · 回调期间 sslVerify/testnet 临时改为当前市场值；finally 只还原「本协程自己备份的那份」
        return $this->runInScopedContext(
            self::SCOPE_MARKET,
            $payload,
            static function (array $ctx) use ($fn) { return $fn($ctx['config']); },
            [
                'sslVerify' => (bool) $cfg['ssl_verify'],
                'testnet'   => (bool) $cfg['testnet'],
            ]
        );
    }

    private function marketFromSymbol(TradingSymbol $s): string
    {
        $type = $s->getType();
        if ($type === TradingSymbol::TYPE_SPOT) {
            return self::MARKET_SPOT;
        }
        return $s->getQuote() === 'USD' ? self::MARKET_COIN_M : self::MARKET_USD_M;
    }

    /**
     * 取「当前 scope + 当前协程」栈顶的市场配置；若无上下文退回 SPOT。
     * 实现完全透传 PerCidContextTrait::getScopedContextTop —— 业务类只关心 fallback 是什么。
     */
    protected function currentMarketConfig(): array
    {
        $ctx = $this->getScopedContextTop(self::SCOPE_MARKET, null);
        if (is_array($ctx) && isset($ctx['config'])) {
            return $ctx['config'];
        }
        return $this->getMarketConfig(self::MARKET_SPOT);
    }

    // ---- 覆盖 AbstractExchange 里的凭证/URL 读取：改为读当前 context 市场 ----

    protected function getBaseUrl(): string
    {
        $cfg = $this->currentMarketConfig();
        return $cfg['testnet'] ? ($cfg['testnet_url'] ?? $cfg['base_url']) : $cfg['base_url'];
    }

    protected function getApiKey(): string
    {
        return (string) $this->currentMarketConfig()['api_key'];
    }

    protected function getSecret(): string
    {
        return (string) $this->currentMarketConfig()['secret'];
    }

    /**
     * 重新构建请求 URL：path 支持 3 种形式：
     *   - "/api/v3/time"      /fapi/v1/time      /dapi/v1/time        Spot/USD-M/COIN-M（绝对路径，直接用）
     *   - "~time"             "~ticker/price"    "~klines"             以 ~ 开头 → 自动拼当前市场 path_prefix
     *   - "time" 无前缀                                               同上（兼容老写法）
     */
    protected function buildRequest(
        string $path,
        string $method,
        array $params,
        bool $signed
    ): array {
        $cfg = $this->currentMarketConfig();
        $prefix = $cfg['path_prefix'];

        // 解析 path 前缀：~ 表示相对路径（PHP 7.4 兼容：用 strpos === 0 替代 str_starts_with）
        $relPath = $path;
        if (strpos($path, '~') === 0) {
            $relPath = substr($path, 1);
        } elseif (strpos($path, '/') !== 0) {
            // 裸写 "klines" → 视为相对路径
            $relPath = $path;
        } else {
            // 绝对路径（/api/v3/... /fapi/v1/...）→ 直接原样（历史代码兼容）
            $relPath = null;
        }
        if ($relPath !== null) {
            $relPath = '/' . ltrim($relPath, '/');
            $path = $prefix . $relPath;
        }

        $baseUrl = $this->getBaseUrl();
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];

        if (!$signed) {
            $query = http_build_query($params);
            $url = $baseUrl . $path;
            if ($query !== '') {
                $url .= '?' . $query;
            }
            return [
                'url'     => $url,
                'headers' => $headers,
                'body'    => '',
                '_cfg'    => $cfg,
            ];
        }

        // --- 签名接口：追加 timestamp/recvWindow；Futures 额外 recvWindow 也兼容 ---
        $params['timestamp'] = $this->getServerTimestampMs();
        $params['recvWindow'] ??= 5000;
        ksort($params);
        $query = http_build_query($params);

        $signature = hash_hmac('sha256', $query, $this->getSecret());
        $url = $baseUrl . $path . '?' . $query . '&signature=' . $signature;

        $headers['X-MBX-APIKEY'] = $this->getApiKey();
        return [
            'url'     => $url,
            'headers' => $headers,
            'body'    => '',
            '_cfg'    => $cfg,
        ];
    }

    /**
     * 检查业务错误：Spot/USD-M/COIN-M 都用 {code, msg} 格式，Spot 的 code 通常为负，
     *   Futures code 可能为正（如 400/-11021）。统一规则：非 0 code 且是整数就抛。
     */
    protected function checkApiError(array $data, string $url): void
    {
        if (isset($data['code']) && is_numeric($data['code']) && (int) $data['code'] !== 0) {
            $code = (int) $data['code'];
            $msg  = (string) ($data['msg'] ?? 'Unknown');
            $this->logger->error('Binance API error', compact('url', 'code', 'msg') + $data);
            throw new ExchangeException("Binance error [{$code}]: {$msg}", $code, $data);
        }
    }

    protected function formatInterval(string $interval): string
    {
        return $interval; // 3 个市场 K 线 tf 完全一致：1m,5m,15m,1h,4h,1d
    }

    // =================================================================
    //  ③ 响应标准化 — 按市场差异字段映射
    // =================================================================

    /**
     * Ticker 标准化：
     *   Spot / USD-M ticker/price 响应格式一样：{symbol,price,time}
     *   COIN-M ticker/price 原生：{symbol,price,ps,...}，时间字段在 `time` 或 `E`（depth/ticker 24hr），
     *     ticker/price 这里一般带 `time`。
     */
    protected function normalizeTicker(array $raw, string $symbol): array
    {
        return [
            'symbol'    => $symbol,
            'price'     => (float) ($raw['price'] ?? $raw['lastPrice'] ?? 0),
            'timestamp' => (int) ($raw['time'] ?? $raw['T'] ?? $raw['E'] ?? 0),
        ];
    }

    protected function normalizeOrderBook(array $raw): array
    {
        $convert = static function (array $levels): array {
            $result = [];
            foreach ($levels as $level) {
                $result[] = [
                    (float) ($level[0] ?? 0),
                    (float) ($level[1] ?? 0),
                ];
            }
            return $result;
        };
        return [
            'bids'       => $convert($raw['bids'] ?? []),
            'asks'       => $convert($raw['asks'] ?? []),
            // COIN-M / USD-M 多了 lastUpdateId / T / E；有就带上
            'updated_at' => (int) ($raw['T'] ?? $raw['lastUpdateId'] ?? 0),
        ];
    }

    protected function normalizeKlines(array $raw): array
    {
        // 3 个市场 kline 数组结构一致：[openTime, open, high, low, close, volume, closeTime, quoteVol, ...]
        $result = [];
        foreach ($raw as $kline) {
            if (!is_array($kline) || !isset($kline[0])) {
                continue;
            }
            $result[] = [
                (int) ($kline[0] ?? 0),
                (float) ($kline[1] ?? 0),
                (float) ($kline[2] ?? 0),
                (float) ($kline[3] ?? 0),
                (float) ($kline[4] ?? 0),
                (float) ($kline[5] ?? 0),
            ];
        }
        return $result;
    }

    protected function normalizeTrades(array $raw): array
    {
        $result = [];
        foreach ($raw as $trade) {
            // Spot aggTrades/trades: {id, price, qty, time, isBuyerMaker}
            // USD-M trades: {id,price,qty,time,isBuyerMaker,...} 一致
            // COIN-M recent trades: {id,price,qty,quoteQty,time,isBuyerMaker,...} 一致
            $result[] = [
                'id'    => (int) ($trade['id'] ?? 0),
                'price' => (float) ($trade['price'] ?? 0),
                'qty'   => (float) ($trade['qty'] ?? 0),
                'time'  => (int) ($trade['time'] ?? 0),
                'side'  => ($trade['isBuyerMaker'] ?? false) ? 'sell' : 'buy',
            ];
        }
        return $result;
    }

    /**
     * 余额标准化：
     *   · SPOT   /api/v3/account 响应 {balances:[{asset,free,locked}]}
     *   · USD-M  /fapi/v2/balance 响应 [{accountAlias, asset, balance, withdrawAvailable, crossWalletBalance, ...}]
     *   · COIN-M /dapi/v1/balance 响应 [{accountAlias, asset, balance, withdrawAvailable,...}]
     * 统一格式：asset => {free, used, total}
     */
    protected function normalizeBalance(array $raw): array
    {
        $result = [];

        // Spot
        if (isset($raw['balances']) && is_array($raw['balances'])) {
            foreach ($raw['balances'] as $item) {
                $free   = (float) ($item['free'] ?? 0);
                $locked = (float) ($item['locked'] ?? 0);
                if ($free <= 0 && $locked <= 0) {
                    continue;
                }
                $result[$item['asset']] = [
                    'free'  => $free,
                    'used'  => $locked,
                    'total' => $free + $locked,
                ];
            }
            return $result;
        }

        // Futures (USD-M / COIN-M) 是 array of items（数字索引）
        if (self::isListArray($raw)) {
            foreach ($raw as $item) {
                $asset  = (string) ($item['asset'] ?? '');
                if ($asset === '') {
                    continue;
                }
                $balance  = (float) ($item['balance'] ?? 0);
                $avail    = $item['availableBalance'] ?? $item['withdrawAvailable'] ?? 0;
                $availF   = (float) $avail;
                $crossBal = isset($item['crossWalletBalance']) ? (float) $item['crossWalletBalance'] : null;
                $total = $crossBal ?? $balance;
                $used  = max(0.0, $total - $availF);
                if ($total <= 0 && $used <= 0) {
                    continue;
                }
                $result[$asset] = [
                    'free'  => $availF,
                    'used'  => $used,
                    'total' => $total,
                ];
            }
        }
        return $result;
    }

    /**
     * 订单标准化（尽量兼容 3 市场字段）：
     *   Spot       → orderId/clientOrderId/symbol/status/type/side/price/origQty/executedQty/time
     *   USD-M POST → 相同，额外：avgPrice, positionSide, reduceOnly, updateTime
     *   COIN-M POST → 除 symbol 外还有 pair；origQty 是币本位数量
     */
    protected function normalizeOrder(array $raw): array
    {
        $side = strtolower($raw['side'] ?? '');
        $origQty   = (float) ($raw['origQty'] ?? 0);
        $executed  = (float) ($raw['executedQty'] ?? 0);
        $remaining = max(0.0, $origQty - $executed);

        // Futures 里 avgPrice 更贴近真实成交价（优先填到 filled_price 扩展字段里）
        // Spot getOrder 通常不返回这个字段，返回 0 占位，调用方可自行算
        $avgPrice = (float) ($raw['avgPrice'] ?? 0);

        return [
            'id'             => (string) ($raw['orderId'] ?? ''),
            'clientOrderId'  => (string) ($raw['clientOrderId'] ?? ''),
            'symbol'         => (string) ($raw['symbol'] ?? ''),
            'pair'           => (string) ($raw['pair'] ?? ''),   // COIN-M 特有 (如 BTCUSD)
            'status'         => strtolower((string) ($raw['status'] ?? '')),
            'type'           => strtolower((string) ($raw['type'] ?? '')),
            'side'           => $side,
            'price'          => (float) ($raw['price'] ?? 0),
            'avg_fill_price' => $avgPrice,
            'amount'         => $origQty,
            'filled'         => $executed,
            'remaining'      => $remaining,
            'reduce_only'    => (bool) ($raw['reduceOnly'] ?? false),      // Futures 专用
            'position_side'  => (string) ($raw['positionSide'] ?? ''),    // Futures 专用 (BOTH/LONG/SHORT)
            'timestamp'      => (int) ($raw['updateTime'] ?? $raw['transactTime'] ?? $raw['time'] ?? 0),
        ];
    }

    // =================================================================
    //  ④ 接口实现（12 个）：全部通过 withMarketContext 进入指定市场，path 用 ~ 相对前缀写法
    // =================================================================

    public function getTicker(string $symbol): array
    {
        return $this->withMarketContext($symbol, function () use ($symbol) {
            $raw = $this->request('~ticker/price', 'GET', [
                'symbol' => $this->formatSymbol($symbol),
            ], false);
            // COIN-M 有时候返回单对象数组（例如 pair=BTCUSD），这里兼容一下：取第一个
            if (self::isListArray($raw) && isset($raw[0]) && is_array($raw[0])) {
                $raw = $raw[0];
            }
            return $this->normalizeTicker($raw, $symbol);
        });
    }

    public function getOrderBook(string $symbol, int $limit = 100): array
    {
        return $this->withMarketContext($symbol, function () use ($symbol, $limit) {
            $raw = $this->request('~depth', 'GET', [
                'symbol' => $this->formatSymbol($symbol),
                'limit'  => $limit,
            ], false);
            return $this->normalizeOrderBook($raw);
        });
    }

    public function getKlines(
        string $symbol,
        string $interval,
        int $limit = 100,
        ?int $startMs = null,
        ?int $endMs = null
    ): array {
        return $this->withMarketContext($symbol, function () use ($symbol, $interval, $limit, $startMs, $endMs) {
            $params = [
                'symbol'   => $this->formatSymbol($symbol),
                'interval' => $this->formatInterval($interval),
                'limit'    => $limit,
            ];
            if ($startMs !== null) {
                $params['startTime'] = $startMs;
            }
            if ($endMs !== null) {
                $params['endTime'] = $endMs;
            }
            $raw = $this->request('~klines', 'GET', $params, false);
            return $this->normalizeKlines($raw);
        });
    }

    public function getTrades(string $symbol, int $limit = 100): array
    {
        return $this->withMarketContext($symbol, function () use ($symbol, $limit) {
            $raw = $this->request('~trades', 'GET', [
                'symbol' => $this->formatSymbol($symbol),
                'limit'  => $limit,
            ], false);
            return $this->normalizeTrades($raw);
        });
    }

    public function getServerTime(): int
    {
        // 时间接口与交易对无关，默认按 SPOT 请求（其他市场也有同名 /time，结果一致）
        return $this->withMarketContext(self::MARKET_SPOT, function (): int {
            $raw = $this->request('~time', 'GET', [], false);
            return (int) ($raw['serverTime'] ?? 0);
        });
    }

    public function getBalance(): array
    {
        // Balance 是全局账户查询。策略：按「当前 scope/cid 栈顶是否有市场上下文」决定；
        //   有上下文（调用方显式先 $this->withMarketContext(market, fn() => getBalance())）→ 只取那个市场的余额；
        //   无上下文 → 依次 SPOT / USD-M / COIN-M 三个账户各取一次合并，asset 冲突时取三者合计。
        if ($this->getScopedContextTop(self::SCOPE_MARKET) !== null) {
            $cfg = $this->currentMarketConfig();
            $path = $this->accountPathForMarket($cfg['market']);
            $raw = $this->request($path, 'GET', [], true);
            return $this->normalizeBalance($raw);
        }

        $merged = [];
        foreach ([self::MARKET_SPOT, self::MARKET_USD_M, self::MARKET_COIN_M] as $m) {
            try {
                $perMarket = $this->withMarketContext($m, function () use ($m) {
                    $path = $this->accountPathForMarket($m);
                    return $this->request($path, 'GET', [], true);
                });
                foreach ($perMarket as $asset => $info) {
                    if (isset($merged[$asset])) {
                        $merged[$asset] = [
                            'free'  => $merged[$asset]['free'] + $info['free'],
                            'used'  => $merged[$asset]['used'] + $info['used'],
                            'total' => $merged[$asset]['total'] + $info['total'],
                        ];
                    } else {
                        $merged[$asset] = $info;
                    }
                }
            } catch (\Throwable $e) {
                // 某市场缺权限/未开通（code -2015 Invalid API-key / -1002 无权限）时忽略，不拖垮整查询
                // 只把非权限类的错误打日志（PHP 7.4 兼容：用 strpos !== false 替代 str_contains）
                if (strpos($e->getMessage(), 'Invalid API-key') === false
                    && strpos($e->getMessage(), 'Permission') === false) {
                    $this->logger->warning('Binance balance skipped for market ' . $m, [
                        'msg' => $e->getMessage(),
                        'code' => $e->getCode(),
                    ]);
                }
            }
        }
        return $merged;
    }

    /** 返回不同市场查询余额的 REST path（PHP 7.4 兼容：用 switch 替代 PHP 8 的 match）*/
    private function accountPathForMarket(string $market): string
    {
        switch ($market) {
            case self::MARKET_SPOT:
                return '/api/v3/account';        // Spot 走标准前缀（buildRequest 里会被保留原样）
            case self::MARKET_USD_M:
                return '/fapi/v2/balance';       // 官方文档 2024 起推荐 v2（字段更齐）
            case self::MARKET_COIN_M:
                return '/dapi/v1/balance';
            default:
                return '/api/v3/account';
        }
    }

    public function createOrder(array $params): array
    {
        if (empty($params['symbol']) || !isset($params['amount'])) {
            throw new ExchangeException("createOrder requires 'symbol' and 'amount' parameters");
        }
        $symbol = (string) $params['symbol'];
        $type   = strtolower((string) ($params['type'] ?? 'limit'));
        if ($type === 'limit' && !isset($params['price'])) {
            throw new ExchangeException("Limit order requires 'price' parameter");
        }

        return $this->withMarketContext($symbol, function (array $cfg) use ($symbol, $params, $type) {
            $orderParams = [
                'symbol' => $this->formatSymbol($symbol),
                'side'   => strtoupper((string) ($params['side'] ?? 'BUY')),
                'type'   => strtoupper($type),
            ];
            // COIN-M 额外需要 pair=BTCUSD；Binance 原生 symbol=BTCUSD_PERP / BTCUSD_250627，
            //   但订单仍需要 pair 参数。从 TradingSymbol 取 pair = base + quote。
            if ($cfg['market'] === self::MARKET_COIN_M) {
                [, $parsed] = $this->resolveMarket($symbol);
                $orderParams['pair'] = $parsed->getBase() . $parsed->getQuote();
            }
            // Futures 里 quantity 和 Spot 一致：订单委托数量（U本位是 quote 对应数量的合约张数？Binance USD-M quantity 仍
            // 指 BTC 的数量，和 Spot 一致；COIN-M quantity 指张数，1 张 = $100 面值（调用方自己换算），这里不做自动换算。
            $orderParams['quantity'] = $params['amount'];

            if ($type === 'limit') {
                $orderParams['price']       = (float) $params['price'];
                $orderParams['timeInForce'] = $params['time_in_force'] ?? 'GTC';
            }
            // MARKET 市价订单 futures/spot 都可不传 price

            if (!empty($params['clientOrderId'])) {
                $orderParams['newClientOrderId'] = $params['clientOrderId'];
            }
            // Futures 额外常用参数：positionSide / reduceOnly / closePosition / leverage
            foreach (['position_side' => 'positionSide', 'reduce_only' => 'reduceOnly', 'close_position' => 'closePosition', 'leverage' => 'leverage'] as $src => $dst) {
                if (isset($params[$src])) {
                    $orderParams[$dst] = $params[$src];
                }
            }

            $raw = $this->request('~order', 'POST', $orderParams, true);
            return $this->normalizeOrder($raw);
        });
    }

    public function cancelOrder(string $orderId, string $symbol): array
    {
        return $this->withMarketContext($symbol, function (array $cfg) use ($orderId, $symbol) {
            $params = ['symbol' => $this->formatSymbol($symbol), 'orderId' => $orderId];
            if ($cfg['market'] === self::MARKET_COIN_M) {
                [, $parsed] = $this->resolveMarket($symbol);
                $params['pair'] = $parsed->getBase() . $parsed->getQuote();
            }
            $raw = $this->request('~order', 'DELETE', $params, true);
            return $this->normalizeOrder($raw);
        });
    }

    public function getOrder(string $orderId, string $symbol): array
    {
        return $this->withMarketContext($symbol, function (array $cfg) use ($orderId, $symbol) {
            $params = ['symbol' => $this->formatSymbol($symbol), 'orderId' => $orderId];
            if ($cfg['market'] === self::MARKET_COIN_M) {
                [, $parsed] = $this->resolveMarket($symbol);
                $params['pair'] = $parsed->getBase() . $parsed->getQuote();
            }
            $raw = $this->request('~order', 'GET', $params, true);
            return $this->normalizeOrder($raw);
        });
    }

    public function getOpenOrders(string $symbol = ''): array
    {
        if ($symbol === '') {
            // 3 个市场 openOrders 一起拉（用户经常会忘记 symbol，但 REST 需要它；这里做一次三市场聚合）
            $merged = [];
            foreach ([self::MARKET_SPOT, self::MARKET_USD_M, self::MARKET_COIN_M] as $m) {
                try {
                    $per = $this->withMarketContext($m, function () use ($m) {
                        switch ($m) {
                            case self::MARKET_SPOT:
                                $path = '/api/v3/openOrders';
                                break;
                            case self::MARKET_USD_M:
                                $path = '/fapi/v1/openOrders';
                                break;
                            case self::MARKET_COIN_M:
                                $path = '/dapi/v1/openOrders';
                                break;
                            default:
                                $path = '/api/v3/openOrders';
                        }
                        $raw = $this->request($path, 'GET', [], true);
                        $result = [];
                        foreach ((array) $raw as $order) {
                            $result[] = $this->normalizeOrder($order);
                        }
                        return $result;
                    });
                    array_push($merged, ...$per);
                } catch (\Throwable $e) {
                    // 忽略单市场权限/网络异常
                }
            }
            return $merged;
        }

        return $this->withMarketContext($symbol, function () use ($symbol) {
            $raw = $this->request('~openOrders', 'GET', [
                'symbol' => $this->formatSymbol($symbol),
            ], true);
            $result = [];
            foreach ((array) $raw as $order) {
                $result[] = $this->normalizeOrder($order);
            }
            return $result;
        });
    }

    // =================================================================
    //  ⑤ 工具
    // =================================================================

    /**
     * PHP 7.4 polyfill for array_is_list()（PHP 8.0+ 内置）。
     * 判断数组是否为「从 0 开始、连续递增、没有空洞」的数字索引列表。
     * 空数组返回 true（同 PHP 8 的语义）。
     */
    private static function isListArray(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * 协程安全的服务器时间戳：优先用服务器时间对齐本地时钟，避免 Binance
     *   返回 Error -1021 "timestamp outside of recvWindow"。
     *
     * 并发安全实现（替代原来的 static $cachedDelta）：
     *   · 用 Swoole\Atomic 的 cmpset(CAS) 做一次性初始化标记
     *   · 状态机：0=未初始化 → 1=初始化中 → 2=已完成
     *   · 第一个抢到 CAS 的协程负责发 /time 请求；其余协程让出 CPU 等待标记变 2
     *   · 最终所有协程复用同一份 $this->serverTimeDelta（毫秒级本地↔服务器时钟偏移）
     */
    protected function getServerTimestampMs(): int
    {
        // 快速路径：已经初始化好，直接用
        if ($this->serverTimeInitAtomic->get() === 2 && $this->serverTimeDelta !== null) {
            return (int) (microtime(true) * 1000) + $this->serverTimeDelta;
        }

        // CAS：谁先把 0 → 1，谁就负责发起一次性 /time HTTP 请求
        if ($this->serverTimeInitAtomic->cmpset(0, 1)) {
            try {
                // ⚠️ 非协程环境（如 PHPUnit stest）Swoole\Coroutine\Http\Client 会 Fatal Error，
                //    必须先判断在协程里再发请求；否则直接退化用本地时钟
                $inCoroutine = class_exists(\Swoole\Coroutine::class, false)
                    && \Swoole\Coroutine::getuid() > 0;

                if ($inCoroutine) {
                    $t0 = (int) (microtime(true) * 1000);
                    $srv = $this->getServerTime();
                    $t1  = (int) (microtime(true) * 1000);
                    if ($srv > 0) {
                        // round-trip / 2 近似估计本地↔服务器时钟偏移
                        $this->serverTimeDelta = $srv - (int) (($t0 + $t1) / 2);
                    } else {
                        $this->serverTimeDelta = 0;
                    }
                } else {
                    // CLI/PHPUnit 非协程环境：跳过 HTTP，delta=0 直接用本地时钟
                    $this->serverTimeDelta = 0;
                }
            } catch (\Throwable $e) {
                // /time 请求失败时退化用本地时钟（delta=0），保证签名 timestamp 不崩
                $this->serverTimeDelta = 0;
            }
            $this->serverTimeInitAtomic->set(2);
        } else {
            // 其他协程：初始化已在进行中 → 轮询等待标记变 2（最多等 2 秒兜底）
            $deadline = microtime(true) + 2.0;
            while ($this->serverTimeInitAtomic->get() !== 2 && microtime(true) < $deadline) {
                if (class_exists(\Swoole\Coroutine::class, false)
                    && \Swoole\Coroutine::getuid() > 0) {
                    \Swoole\Coroutine::sleep(0.001); // 协程让出，避免忙等
                } else {
                    usleep(1000);
                }
            }
            // 极端超时兜底（理论上不会发生，/time 接口极快）
            if ($this->serverTimeDelta === null) {
                $this->serverTimeDelta = 0;
            }
        }

        return (int) (microtime(true) * 1000) + $this->serverTimeDelta;
    }
}
