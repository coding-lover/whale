# 交易所服务使用文档

> 统一交易所 SDK 服务层，屏蔽 Binance / OKX 等交易所 API 的格式差异，
> 提供标准的交易对格式、统一的行情 / 交易接口，支持代理、SSL 开关和独立日志。

---

## 目录

- [1. 架构概览](#1-架构概览)
- [2. 标准交易对格式](#2-标准交易对格式)
  - [2.1 格式总表](#21-格式总表)
  - [2.2 交割周期别名](#22-交割周期别名)
  - [2.3 各交易所原生格式对照](#23-各交易所原生格式对照)
- [3. 快捷函数](#3-快捷函数)
- [4. 快速上手](#4-快速上手)
  - [4.1 获取交易所实例](#41-获取交易所实例)
  - [4.2 交易对格式转换](#42-交易对格式转换)
  - [4.3 行情接口调用](#43-行情接口调用)
  - [4.4 交易接口调用](#44-交易接口调用)
- [5. 配置说明](#5-配置说明)
- [6. 设计模式与文件结构](#6-设计模式与文件结构)
- [7. 日志](#7-日志)
- [8. 扩展新交易所](#8-扩展新交易所)

---

## 1. 架构概览

```
┌──────────────────────────────────────────────────────────────┐
│                      业务代码（Controller / Service / Task）      │
│   exchange('binance')->formatSymbol('BTC/USDT:QUARTER')          │
└──────────────────────────────────────────────────────────────┘
                              ▲
                              │ 通过 ExchangeManager::exchange() 统一入口
                              │
┌──────────────────────────────────────────────────────────────┐
│  ExchangeManager                                              │
│    ├── 统一入口（懒加载 + 防并发重复实例化）                      │
│    ├── 代理开关 / SSL 验证开关 / 调试日志开关                    │
│    └── 实例注册表 BinanceExchange / OkxExchange                │
└──────────────────────────────────────────────────────────────┘
             ▲                           ▲
             │ 继承                       │ 继承
   ┌─────────┴─────────┐         ┌───────┴──────────┐
   │ BinanceExchange    │         │ OkxExchange       │
   └─────────┬─────────┘         └───────┬──────────┘
             │ 注入                       │ 注入
             ▼                           ▼
  BinanceSymbolFormatter          OkxSymbolFormatter
          └── 格式双向转换策略 ──┘   （实现 SymbolFormatterInterface）
             │                           │
             ▼                           ▼
        TradingSymbol（标准值对象，周期别名日期推算、归一化）
```

---

## 2. 标准交易对格式

本框架所有交易所接口（`getTicker`、`createOrder` 等）的 `symbol` 参数都使用以下**标准格式**。
内部会自动转换为 Binance / OKX 的原生格式；反向解析交易所返回值时也会自动归一化。

### 2.1 格式总表

| 标准格式 | 类型 | 说明 |
|---------|------|------|
| `BTC/USDT` | 现货（Spot） | 斜杠分隔：`基础货币 / 计价货币` |
| `BTC/USDT:SWAP` | U本位永续合约 | `:SWAP` 标识永续 |
| `BTC/USD:SWAP` | 币本位永续合约 | `quote = USD`，即币本位 |
| `BTC/USDT:THIS_WEEK` | 周合约 - 本周 | 按交割周期别名（推荐） |
| `BTC/USDT:NEXT_WEEK` | 周合约 - 下周 | 同上 |
| `BTC/USDT:QUARTER` | 季合约 - 当季 | 同上 |
| `BTC/USDT:BI_QUARTER` | 季合约 - 次季 | 同上 |
| `BTC/USDT:CI_QUARTER` | 季合约 - 第三季 | 同上 |
| `BTC/USDT:FUT-260831` | 显式日期交割合约 | 不匹配任何周期别名时使用 |

### 2.2 交割周期别名

交割时间规则（Binance / OKX 完全一致）：

| 别名 | 交割时间 | 说明 |
|------|---------|------|
| `THIS_WEEK` | 本周五 08:00 UTC | 如果已过本周五交割点，自动顺延到下周五 |
| `NEXT_WEEK` | 下周五 08:00 UTC | THIS_WEEK + 7 天 |
| `QUARTER` | 当前季度末月最后一个周五 08:00 UTC | Q1末=3月，Q2末=6月，Q3末=9月，Q4末=12月 |
| `BI_QUARTER` | 下一个季度末月最后一个周五 | QUARTER + 1 季 |
| `CI_QUARTER` | 下两个季度末月最后一个周五 | QUARTER + 2 季 |

> 周期别名是**本地便利语法**，发往交易所前会自动推算出实际的 `YYMMDD` 日期。
> 从交易所反向解析时，如果显式日期恰好匹配 5 种别名之一，会**自动归一化**为别名形式。

### 2.3 各交易所原生格式对照

| 标准格式 | Binance 原生 | OKX 原生 |
|---------|------------|---------|
| `BTC/USDT` (现货) | `BTCUSDT` | `BTC-USDT` |
| `BTC/USDT:SWAP` (U本位永续) | `BTCUSDT`（不同端点） | `BTC-USDT-SWAP` |
| `BTC/USD:SWAP` (币本位永续) | `BTCUSD_PERP` | `BTC-USD-SWAP` |
| `BTC/USDT:QUARTER` (当季) | `BTCUSDT_260925` (例) | `BTC-USDT-260925` (例) |
| `BTC/USDT:FUT-260831` (显式日期) | `BTCUSDT_260831` | `BTC-USDT-260831` |

---

## 3. 快捷函数

所有函数在框架启动时自动加载（文件 `app/common.php`），可在任意业务代码中直接调用：

| 函数 | 返回类型 | 说明 |
|------|---------|------|
| `app()` | `Framework` | 获取 Framework 单例 |
| `container()` | `Container` | 获取依赖注入容器 |
| `config($key?, $default?)` | `Config\|mixed` | 获取配置实例或配置值 |
| `logger()` | `Logger` | 获取主日志 |
| `cache()` | `RedisCache` | 获取 Redis 缓存实例 |
| `db()` | `MysqlPool` | 获取 MySQL 连接池 |
| `exchange_manager()` | `ExchangeManager` | 获取交易所服务管理器 |
| **`exchange($name)`** | `ExchangeInterface` | **核心快捷入口**：获取指定交易所适配器 |

最常用写法：

```php
$symbol = exchange('binance')->formatSymbol('BTC/USDT:QUARTER');
$ticker = exchange('okx')->getTicker('BTC/USDT:SWAP');
```

---

## 4. 快速上手

### 4.1 获取交易所实例

```php
use App\Services\Exchanges\ExchangeManager;

// 方式 1：快捷函数（推荐）
$binance = exchange('binance');
$okx     = exchange('okx');

// 方式 2：通过容器
$manager = container()->get(ExchangeManager::class);
$binance = $manager->exchange('binance');

// 方式 3：全局函数获取 manager 再取
$okx = exchange_manager()->exchange('okx');
```

支持的交易所名称：

| 名称 | 类 |
|------|---|
| `'binance'` | `App\Services\Exchanges\Adapters\BinanceExchange` |
| `'okx'` | `App\Services\Exchanges\Adapters\OkxExchange` |

### 4.2 交易对格式转换

#### 正向：标准 → 交易所原生

```php
$binance = exchange('binance');
$okx     = exchange('okx');

// 现货
$binance->formatSymbol('BTC/USDT');                // 'BTCUSDT'
$okx->formatSymbol('BTC/USDT');                     // 'BTC-USDT'

// 永续
$binance->formatSymbol('BTC/USDT:SWAP');            // 'BTCUSDT'
$binance->formatSymbol('BTC/USD:SWAP');             // 'BTCUSD_PERP'
$okx->formatSymbol('BTC/USDT:SWAP');                // 'BTC-USDT-SWAP'

// 交割合约 - 周期别名（自动推算日期）
$binance->formatSymbol('BTC/USDT:QUARTER');         // 'BTCUSDT_260925'
$okx->formatSymbol('BTC/USDT:THIS_WEEK');           // 'BTC-USDT-260828'

// 交割合约 - 显式日期
$okx->formatSymbol('BTC/USDT:FUT-260831');          // 'BTC-USDT-260831'
```

#### 反向：交易所原生 → 标准（自动归一化别名）

```php
// 现货 / 永续
$okx->parseSymbol('BTC-USDT-SWAP');             // BTC/USDT:SWAP
$binance->parseSymbol('BTCUSD_PERP');            // BTC/USD:SWAP
$binance->parseSymbol('BTCUSDT');                // BTC/USDT（默认 TYPE_SPOT）
$binance->parseSymbol('BTCUSDT', TYPE_SWAP);     // BTC/USDT:SWAP（需要明确 defaultType）

// 交割合约：自动归一化为周期别名（若匹配）
$okx->parseSymbol('BTC-USDT-260925');            // BTC/USDT:QUARTER （假设这天是季度末最后一个周五）
$okx->parseSymbol('BTC-USDT-260831');            // BTC/USDT:FUT-260831（不匹配别名，保留显式）

// TradingSymbol 对象可字符串化直接得到标准格式
$symbol = $okx->parseSymbol('BTC-USDT-260925');
echo $symbol;                                      // 'BTC/USDT:QUARTER'
echo $symbol->getResolvedDeliveryDate();           // '260925'（别名推算出的日期不变）
```

> **关于 Binance 现货/U本位永续歧义**：
> Binance 原生格式 `BTCUSDT` 同时代表现货和 U本位永续（仅 API 端点不同）。
> 反向解析时可通过第二个参数 `$defaultType`（默认 `TYPE_SPOT`）明确指定类型。
> OKX 则无此问题，永续一定以 `-SWAP` 结尾。

### 4.3 行情接口调用

所有行情接口的 `symbol` 参数都接受**标准格式**：

```php
$binance = exchange('binance');

// 获取最新价
$ticker = $binance->getTicker('BTC/USDT:SWAP');
// 返回：[
//   'symbol'   => 'BTC/USDT:SWAP',  // 已标准化
//   'price'    => 58000.12,
//   'volume24h'=> 12345.67,
//   'high24h'  => 59000,
//   'low24h'   => 57000,
//   'timestamp'=> 1787364493553,
// ]

// 获取订单簿深度
$book = $binance->getOrderBook('BTC/USDT:QUARTER', 10);
// 返回：[ 'bids' => [[price, qty],...], 'asks' => [...], 'timestamp' => ... ]

// 获取 K 线
$klines = $binance->getKlines('BTC/USDT', '1h', 100, time() - 3600);
// 返回：[[open_ts, open, high, low, close, volume, close_ts], ...]
```

统一的接口清单（`ExchangeInterface`）：

| 方法 | 说明 |
|-----|------|
| `getTicker($symbol)` | 获取最新行情 |
| `getOrderBook($symbol, $limit)` | 获取订单簿 |
| `getKlines($symbol, $interval, $limit, $start, $end)` | 获取 K 线 |
| `getBalances()` | 查询账户余额 |
| `createOrder($symbol, $side, $type, $amount, $price, $params)` | 下单 |
| `cancelOrder($symbol, $orderId)` | 撤单 |
| `getOrder($symbol, $orderId)` | 查询单个订单 |
| `getOpenOrders($symbol)` | 查询挂单 |
| `getMyTrades($symbol, $limit)` | 查询最近成交 |
| `getServerTime()` | 获取交易所服务器时间 |
| **`formatSymbol($symbol)`** | 标准 → 交易所原生 |
| **`parseSymbol($native, $defaultType)`** | 交易所原生 → 标准 |

### 4.4 交易接口调用

```php
$okx = exchange('okx');

// 市价买入 0.1 BTC/USDT:SWAP
$order = $okx->createOrder(
    symbol: 'BTC/USDT:SWAP',
    side:   'buy',
    type:   'market',
    amount: 0.1
);

// 限价卖出 1 ETH/USDT:THIS_WEEK（本周期货）
$order = $okx->createOrder(
    symbol: 'ETH/USDT:THIS_WEEK',
    side:   'sell',
    type:   'limit',
    amount: 1.0,
    price:  2800.00
);

$orderId = $order['id'];

// 撤单
$okx->cancelOrder('ETH/USDT:THIS_WEEK', $orderId);

// 查询订单
$order = $okx->getOrder('ETH/USDT:THIS_WEEK', $orderId);

// 查询余额
$balances = $okx->getBalances();
// 返回：[ 'USDT' => ['free'=>1000, 'locked'=>200], 'BTC' => [...], ... ]
```

---

## 5. 配置说明

配置文件：`config/exchanges.php` + `.env` 环境变量。

| 配置项 | `.env` 变量 | 说明 | 默认 |
|-------|------------|------|------|
| `enabled` | `EXCHANGES_ENABLED` | 总开关 | `true` |
| `debug_log` | `EXCHANGES_DEBUG_LOG` | HTTP 请求/响应调试日志开关 | `false` |
| `exchanges.binance.enabled` | `BINANCE_ENABLED` | Binance 开关 | `true` |
| `exchanges.binance.testnet` | `BINANCE_TESTNET` | Binance 测试网 | `false` |
| `exchanges.binance.api_key` | `BINANCE_API_KEY` | API Key | - |
| `exchanges.binance.secret_key` | `BINANCE_SECRET_KEY` | Secret Key | - |
| `exchanges.binance.ssl_verify` | `BINANCE_SSL_VERIFY` | SSL 证书验证（本地代理时建议关闭） | `true` |
| `exchanges.binance.proxy.enabled` | `BINANCE_PROXY_ENABLED` | 代理开关 | `false` |
| `exchanges.binance.proxy.host` | `BINANCE_PROXY_HOST` | 代理主机 | `127.0.0.1` |
| `exchanges.binance.proxy.port` | `BINANCE_PROXY_PORT` | 代理端口 | `6666` |
| `exchanges.okx.*` | `OKX_*` | OKX 对应配置（同上） | - |

示例 `.env`：

```dotenv
EXCHANGES_ENABLED=true
EXCHANGES_DEBUG_LOG=true

# Binance
BINANCE_ENABLED=true
BINANCE_TESTNET=false
BINANCE_API_KEY=xx
BINANCE_SECRET_KEY=xx
BINANCE_SSL_VERIFY=false
BINANCE_PROXY_ENABLED=true
BINANCE_PROXY_HOST=127.0.0.1
BINANCE_PROXY_PORT=6666

# OKX
OKX_ENABLED=true
OKX_SSL_VERIFY=false
OKX_PROXY_ENABLED=true
OKX_PROXY_PORT=6666
```

运行时动态开关：

```php
$manager = exchange_manager();

// 临时关闭代理（会影响该管理器后续所有请求）
$manager->disableProxy();

// 启用调试日志（打印所有 HTTP 请求的方法、URL、代理、耗时、响应摘要）
$manager->enableDebugLog();
```

---

## 6. 设计模式与文件结构

相关文件目录：

```
app/Services/Exchanges/
├── ExchangeInterface.php              # 统一接口（11 个核心方法 + 格式转换）
├── AbstractExchange.php               # 基类（HTTP 请求、签名、速率限制）
├── ExchangeManager.php                # 单一入口 + 代理/日志配置
├── ExchangeException.php              # 自定义异常
├── TradingSymbol.php                  # 标准交易对值对象（解析、日期推算、别名归一化）
├── Formatters/                        # 格式转换策略（策略模式 + 模板方法）
│   ├── SymbolFormatterInterface.php   # 策略接口（双向转换）
│   ├── AbstractSymbolFormatter.php    # 模板方法基类 + quote 白名单
│   ├── BinanceSymbolFormatter.php     # Binance 规则
│   └── OkxSymbolFormatter.php         # OKX 规则
└── Adapters/                          # 交易所适配器
    ├── BinanceExchange.php            # Binance 签名、端点、错误处理
    └── OkxExchange.php                # OKX 签名、端点、错误处理
```

### 设计模式

| 模式 | 应用位置 | 作用 |
|-----|---------|-----|
| **适配器模式** | `BinanceExchange` / `OkxExchange` 实现 `ExchangeInterface` | 将不同交易所 API 统一成一套接口 |
| **策略模式** | `SymbolFormatterInterface` + 两种实现 | 把格式转换规则抽出为独立策略，便于新增交易所 |
| **模板方法** | `AbstractSymbolFormatter::format()` 分派 spot/swap/futures | 复用流程骨架，子类实现细节 |
| **双重检查锁 + 懒加载** | `ExchangeManager::exchange()` | 并发安全地只实例化一次适配器 |
| **值对象** | `TradingSymbol` | 表示不可变的交易对，解析 / 日期推算 / 归一化 封装在内 |

### 类依赖方向（没有循环依赖）

```
TradingSymbol (无依赖)
    ▲
    │ 被使用
    ├────────────────────────────
    │                            │
Formatters                 ExchangeInterface (不依赖具体实现)
    ▲                            ▲
    │                            │
BinanceFormatter        AbstractExchange（注入 Formatter）
OkxFormatter                  ▲
                              │
                        BinanceExchange / OkxExchange
                              ▲
                              │
                        ExchangeManager（按名创建，管理实例）
```

---

## 7. 日志

交易所服务的日志单独存放在 `exchange-service_YYYY-MM-DD.log`（不与主日志混合），按日期自动拆分。

```
logs/
├── app_2026-08-23.log
└── exchange-service_2026-08-23.log   ← 交易所独立日志
```

开启调试日志（`EXCHANGES_DEBUG_LOG=true`）后，每条 HTTP 请求都会打印：

```
[2026-08-23 10:15:30] [29301] [debug] Exchange HTTP request starting {
    "exchange": "binance",
    "method": "GET",
    "host":   "api.binance.com",
    "path":   "/api/v3/time",
    "proxy":  "tcp://127.0.0.1:6666"
}
[2026-08-23 10:15:30] [29301] [debug] Exchange HTTP request completed {
    "status": 200,
    "elapsed_ms": 132,
    "response_preview": "{\"serverTime\":1787364493553}"
}
```

通过快捷函数打印日志：

```php
logger()->info('业务日志 → app 日志');
exchange_manager()->enableDebugLog();  // 打开交易所 HTTP 请求日志（exchange-service 日志）
```

---

## 8. 扩展新交易所

以新增 Bybit 为例，只需**新增 3 个文件、修改 1 个配置**，无需修改现有代码。

### 步骤 1：创建 BybitSymbolFormatter

```php
// app/Services/Exchanges/Formatters/BybitSymbolFormatter.php
namespace App\Services\Exchanges\Formatters;

use App\Services\Exchanges\TradingSymbol;

class BybitSymbolFormatter extends AbstractSymbolFormatter
{
    protected function formatSpot(TradingSymbol $symbol): string
    {
        return $symbol->getBase() . $symbol->getQuote();
    }

    protected function formatSwap(TradingSymbol $symbol): string
    {
        return $symbol->getBase() . $symbol->getQuote();
    }

    protected function formatFutures(TradingSymbol $symbol, string $deliveryDate): string
    {
        return $symbol->getBase() . $symbol->getQuote() . '-' . $deliveryDate;
    }

    public function parseExchangeSymbol(string $native, string $defaultType = TradingSymbol::TYPE_SPOT): TradingSymbol
    {
        // 参考 BinanceSymbolFormatter 按 Bybit 规则实现
    }

    public function getExchangeName(): string { return 'bybit'; }
}
```

### 步骤 2：创建 BybitExchange 适配器

```php
// app/Services/Exchanges/Adapters/BybitExchange.php
use App\Services\Exchanges\AbstractExchange;
use App\Services\Exchanges\Formatters\BybitSymbolFormatter;

class BybitExchange extends AbstractExchange
{
    public function __construct($appConfig, $logger)
    {
        parent::__construct($appConfig, $logger, new BybitSymbolFormatter());
    }

    // 实现签名、端点、错误映射等（参照 BinanceExchange）
    // 具体方法签名见 ExchangeInterface
}
```

### 步骤 3：在 ExchangeManager 里注册（1 行）

[ExchangeManager.php](file:///Users/wmc/data/trae/project/whale/app/Services/Exchanges/ExchangeManager.php) 的
`$classMap` 属性添加：

```php
protected array $classMap = [
    'binance' => Adapters\BinanceExchange::class,
    'okx'     => Adapters\OkxExchange::class,
    'bybit'   => Adapters\BybitExchange::class,  // ← 新增
];
```

### 步骤 4：配置文件 `config/exchanges.php`

复制 Binance 的配置块改名为 `bybit`，并在 `.env` 添加对应变量即可。

### 使用

```php
exchange('bybit')->getTicker('BTC/USDT');
exchange('bybit')->formatSymbol('BTC/USDT:QUARTER');
```

**完成。** 现有框架代码与测试代码都不需要改动。
