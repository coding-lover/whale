# Sikelan 交易 / 回测系统（Trader）使用手册

> 位置：`app/Services/Trader/`
>
> 参考 Freqtrade 开源回测引擎架构实现，100% 遵守本地框架设计原则：
> 组件化 / 接口隔离 / 依赖注入，回测 / Dry-run / 实盘共用同一套撮合逻辑。

---

## 目录

- [零、命令行一行回测（trader:backtest，最快上手）](#零命令行一行回测traderbacktest最快上手)
  - [零参数快速开始](#零参数快速开始)
  - [常用示例](#常用示例)
  - [参数速查表](#参数速查表)
- [零·2 创建策略脚手架（trader:make-strategy）](#零2-创建策略脚手架tradermake-strategy)
  - [最简用法](#最简用法-1)
  - [三个模板](#三个模板)
  - [参数速查表](#参数速查表-1)
- [一、创建回测](#一创建回测)
  - [1.1 四种创建方式总览](#11-四种创建方式总览)
  - [1.2 方式一：loadDataProvider + newBacktestingByName（推荐）](#12-方式一loaddataprovider--newbacktestingbyname推荐)
  - [1.3 方式二：createArrayDataProvider + 手动加载 + newBacktestingByName](#13-方式二createarraydataprovider--手动加载--newbacktestingbyname)
  - [1.4 方式三：createStrategyByName + newBacktesting](#14-方式三createstrategybyname--newbacktesting)
  - [1.5 方式四：纯数组配置 build（无容器 / 单元测试）](#15-方式四纯数组配置-build无容器--单元测试)
  - [1.6 策略别名注册表](#16-策略别名注册表)
  - [1.7 运行结果分析](#17-运行结果分析)
- [二、回测原理](#二回测原理)
  - [2.1 整体架构](#21-整体架构)
  - [2.2 信号矩阵：策略与引擎的通信协议](#22-信号矩阵策略与引擎的通信协议)
  - [2.3 策略开发三步法](#23-策略开发三步法)
  - [2.4 逐 K 线推进主循环](#24-逐-k-线推进主循环)
  - [2.5 防前视偏差核心机制](#25-防前视偏差核心机制)
  - [2.6 平仓优先级顺序](#26-平仓优先级顺序)
  - [2.7 风控配置：止损 / ROI / 追踪止损 / HOLD 超时](#27-风控配置止损--roi--追踪止损--hold-超时)
  - [2.8 保护机制：冷却锁 / 开仓上限](#28-保护机制冷却锁--开仓上限)
- [三、指标计算系统（IndicatorCalculator）](#三指标计算系统indicatorcalculator)
  - [3.1 为什么用 IndicatorCalculator](#31-为什么用-indicatorcalculator)
  - [3.2 前置依赖 & 精度配置](#32-前置依赖--精度配置)
  - [3.3 契约保证（通用行为）](#33-契约保证通用行为)
  - [3.4 33 个指标速查表](#34-33-个指标速查表)
  - [3.5 使用场景与组合策略](#35-使用场景与组合策略)
  - [3.6 三步上手示例](#36-三步上手示例)
  - [3.7 已知坑点与内置修复](#37-已知坑点与内置修复)
- [附录](#附录)
  - [A. 目录与模块总览](#a-目录与模块总览)
  - [B. 默认配置 config/trader.php](#b-默认配置-configtraderphp)
  - [C. PerformanceReport 30+ 指标定义](#c-performancereport-30指标定义)
  - [D. ResultExporter 导出 JSON / CSV](#d-resultexporter-导出-json--csv)
  - [E. 单元测试覆盖清单](#e-单元测试覆盖清单)

---

# 零、命令行一行回测（trader:backtest，最快上手）

> 不想写 PHP 代码？`trader:backtest` 命令把"加载数据（CSV 缺失自动下载）→ 装配策略 →
> 运行回测 → 输出绩效报告"整条链路封装成一条命令。所有参数都有默认值，零参数即可跑。

## 零参数快速开始

```bash
# 等价于：binance · BTC/USDT · 1h · MeanRevStd 策略，CSV 缺失自动下载近 7 天
php bin/sikelan trader:backtest
```

输出示例（彩色人类可读报告）：

```
[INFO] 回测完成
────────────────────────────────────────────────────
  策略          Bollinger(20,2.0σ) + RSI(14,30/65) MeanReversion [v1.0-std]
  交易所/周期   binance · 1h
  交易对        BTC/USDT, ETH/USDT
  回测区间      2026-08-25 ~ 2026-09-02 (UTC)
  初始/期末资金 10,000.00 → 10,002.64 USDT
────────────────────────────────────────────────────
  总收益率      +0.03%  (净利 2.64)
  年化收益      +1.30%
  夏普比率      2.63
  索提诺比率    5.53
  卡玛比率      9.34
  最大回撤      -0.14%  (-13.91)
────────────────────────────────────────────────────
  交易总数      2  (盈 1 / 亏 1)
  胜率          50.00%
  盈亏比        1.87
  利润因子      1.87
  平均持仓      270.00 分钟
  信号/拒绝     3 / 1
────────────────────────────────────────────────────
```

## 常用示例

```bash
# 多交易对（逗号分隔，天然支持组合回测）
php bin/sikelan trader:backtest --symbol=BTC/USDT,ETH/USDT

# 指定策略 + 15分钟周期 + 最近 30 天（同时作为 CSV 缺失时的下载天数）
php bin/sikelan trader:backtest --strategy=EmaCross20_50 --timeframe=15m --days=30

# 指定回测区间（UTC 自然日，含首尾）
php bin/sikelan trader:backtest --from=2026-01-01 --to=2026-03-31

# 只用本地已有 CSV，不联网下载（CI / 离线环境）
php bin/sikelan trader:backtest --no-download

# 机器可读 JSON（30+ 指标全量输出，便于脚本/看板处理）
php bin/sikelan trader:backtest --json

# 先看回测计划和每个 pair 的 K 线数，不实际运行（排错首选）
php bin/sikelan trader:backtest --dry-run

# 查看 config/trader.php 已注册的所有策略别名
php bin/sikelan trader:backtest --list-strategies
```

## 参数速查表

| 参数 | 默认值 | 说明 |
|------|--------|------|
| `--exchange=NAME` | `binance` | 交易所 binance/okx（env `BACKTEST_EXCHANGE`） |
| `--symbol=P1,P2` | `BTC/USDT` | 交易对，逗号分隔多个（env `BACKTEST_SYMBOLS`；别名 `--symbols`） |
| `--timeframe=TF` | `1h` | 周期 1m/5m/15m/30m/1h/4h/1d/1w（别名 `--interval`；env `BACKTEST_TIMEFRAME`） |
| `--strategy=ALIAS` | `MeanRevStd` | 策略别名或完整类名（`--list-strategies` 查看；env `BACKTEST_STRATEGY`） |
| `--days=N` | — | 回测最近 N 天；**同时**作为 CSV 缺失时的自动下载天数。与 `--from/--to` 二选一 |
| `--from=YYYY-MM-DD` | — | 回测起始日（含，UTC）；CSV 缺失时也按此区间下载 |
| `--to=YYYY-MM-DD` | — | 回测结束日（含，UTC） |
| `--capital=N` | config `10000` | 初始资金（env `BACKTEST_CAPITAL`） |
| `--warmup=N` | `60` | 指标预热 K 线数。**K 线不足时调小**（如 7 天 1h 仅 168 根），数据充足可调大 |
| `--no-download` | 关 | CSV 缺失时不自动下载，直接报错并给出手动下载命令 |
| `--allow-gaps` | 关 | 允许 K 线存在缺口（默认严格校验周期连续/对齐） |
| `--list-strategies` | — | 列出已注册策略别名后退出 |
| `--json` | 关 | JSON 输出全部指标 |
| `--dry-run` | — | 只打印计划 + 每个 pair 的 K 线数，不运行 |
| `-h, --help` | — | 查看帮助 |

**行为说明**：

- 数据文件位于 `runtime/trader/data/<exchange>/<SYMBOL>_<TF>.csv`；缺失时自动调
  `trader:download-klines` 下载（时间窗口由 `--days` / `--from` / `--to` 决定，都不给则下载近 7 天）。
- 回测的交易对/周期**无需重复声明**——自动从已加载数据推导（见 [1.1](#11-四种创建方式总览)）。
- 所有参数校验失败 / 下载失败 / 策略未找到都会输出红色 `[ERROR]` + 修复提示，不会抛未捕获异常。
- 跑完没有交易时会给出黄色 `[WARN]` 提示（常见原因：天数太短、warmup 太大、策略不匹配行情）。

---

# 零·2 创建策略脚手架（trader:make-strategy）

> 一行创建策略类文件 + 自动注册到 `config/trader.php` 的 `strategies` 注册表，
> 省去手写类骨架和手动改配置的重复劳动。

## 最简用法

```bash
# 只给别名 --name；类名自动 = 别名 + 'Strategy'，默认 ema 模板
php bin/sikelan trader:make-strategy --name=MyStrat
```

执行后会：
1. 生成 `app/Services/Trader/Strategies/MyStratStrategy.php`（EMA 金叉死叉完整模板）
2. 在 `config/trader.php` 的 `strategies` 数组追加：
   ```php
   'MyStrat' => [
       'class'     => \App\Services\Trader\Strategies\MyStratStrategy::class,
       'construct' => [20, 50, 0.003],
   ],
   ```
3. 自动 `php -l` 语法校验
4. 提示下一步：`php bin/sikelan trader:backtest --strategy=MyStrat`

## 三个模板

| `--template` | 说明 | 构造参数 |
|---|---|---|
| `ema`（默认） | EMA 金叉死叉，完整可跑，含止损/ROI/风控 | `emaShort, emaLong, filterPct` |
| `meanrev` | 布林带 + RSI 均值回归，含 trailing stop / maxHoldBars | `bbPeriod, bbStdMult, rsiPeriod, rsiOversold, rsiOverbought, volFilterFactor` |
| `blank` | 空白骨架，只留 3 个必须方法的 TODO，自定义逻辑 | 无（config 用简写 `'alias' => \Class::class`） |

**常用示例**：

```bash
# 指定类名（与别名不同）
php bin/sikelan trader:make-strategy --name=MacdTrend --class=MacdTrendStrategy

# 均值回归模板
php bin/sikelan trader:make-strategy --name=MeanRev2 --template=meanrev

# 覆盖构造参数（按模板顺序，逗号分隔）
php bin/sikelan trader:make-strategy --name=FastEma --params=10,20,0.001

# 自定义保存目录（命名空间从目录自动推导）
php bin/sikelan trader:make-strategy --name=MyStrat --dir=app/MyStrats

# 只生成类文件，不写 config
php bin/sikelan trader:make-strategy --name=MyStrat --no-register

# 先看计划不执行
php bin/sikelan trader:make-strategy --name=MyStrat --dry-run

# 已存在时覆盖
php bin/sikelan trader:make-strategy --name=MyStrat --force
```

## 参数速查表

| 参数 | 默认值 | 说明 |
|------|--------|------|
| `--name=ALIAS` | **必填** | 策略别名（config 的 key；只能含字母/数字/_/-） |
| `--class=CLASS` | 别名 + `Strategy` | 类名（可选，必须符合 PHP 标识符） |
| `--dir=PATH` | `app/Services/Trader/Strategies` | 保存目录；相对路径基于项目根 |
| `--namespace=NS` | 从目录自动推导 | 命名空间（目录在 `app/` 下时自动推为 `App\...`） |
| `--template=ema\|meanrev\|blank` | `ema` | 模板选择 |
| `--params=v1,v2,...` | 模板默认值 | 构造参数值，逗号分隔（int/float/带引号字符串） |
| `--no-register` | 关 | 只生成类文件，不写入 config |
| `--config=PATH` | 项目 `config/trader.php` | 指定要修改的配置文件路径 |
| `-f, --force` | 关 | 类文件已存在时覆盖 |
| `--dry-run` | — | 只打印计划，不生成文件、不改 config |
| `-h, --help` | — | 查看帮助 |

**安全机制**：
- 别名只能含 `A-Za-z0-9_-`，避免破坏 PHP 数组 key
- 类文件已存在时默认拒绝（`--force` 才覆盖）
- 别名已存在于 config 时报错（即使类文件不同名），防止重复注册
- config 注入用 `token_get_all` 精确定位 `strategies` 子数组，不破坏注释 / `env()` / 其他结构
- 生成后自动 `php -l` 校验，失败给出警告

---

# 一、创建回测

## 1.1 四种创建方式总览

| 方式 | 入口方法 | 数据加载 | 策略实例化 | 适用场景 | 复杂度 |
|------|---------|---------|----------|---------|-------|
| **① 推荐（单对）** | `loadDataProvider` + `newBacktestingByName` | 自动（CSV 存在直接读，不存在自动下载） | 别名自动 | 单交易对回测 / 快速验证 | ⭐ |
| **①-2 推荐（多对）** | `loadDataProviderBatch` + `newBacktestingByName` | 批量自动，塞同一个 DataProvider | 别名自动 | 多交易对组合回测 | ⭐⭐ |
| ② 手动 | `createArrayDataProvider` + `newBacktestingByName` | 手动加载 Candle[] | 别名自动 | 数据来自 DB / 自定义来源 | ⭐⭐ |
| ③ 分步 | `createStrategyByName` + `newBacktesting` | 手动加载 | 别名 → 可覆盖构造参数 | 需要拿到策略实例 / GridSearch 调参 | ⭐⭐⭐ |
| ④ 纯数组 | `build` + `createStrategyByName` | 手动加载 | 别名 / 完整类名 | 单元测试 / 无容器环境 | ⭐⭐⭐ |

> 所有方式最终都返回 `Backtesting` 实例，调用 `->run()` 即可跑回测。
> `run()` 的交易对和周期参数**全部可选**：省略时自动从已加载的 DataProvider 推导（`run()` 零参数即可）；显式传参可只回测部分交易对、限定时间窗口。
> 注意 `run()` 的交易对参数是 `array`——天然支持多交易对，批量加载用 **①-2**。

---

## 1.2 方式一：loadDataProvider + newBacktestingByName（推荐）

**适用场景**：日常开发、CLI 命令、快速验证策略效果。

**特点**：一行加载数据——CSV 存在则直接读取，不存在则自动调用 `trader:download-klines` 下载。

```php
use App\Services\Trader\BacktestServiceProvider;
use App\Services\Trader\PerformanceReport;

// 1) 一行加载数据（CSV 不存在时自动下载最近 7 天）
$dp = BacktestServiceProvider::loadDataProvider('binance', 'BTC/USDT', '1h');

// 1') 如果 CSV 不存在且想自动下载更多天数：
// $dp = BacktestServiceProvider::loadDataProvider('binance', 'BTC/USDT', '1h', ['days' => 30]);

// 2) 一行装配回测（用 config/trader.php 里的策略别名）
$backtest = BacktestServiceProvider::newBacktestingByName(
    container(),          // 全局容器（自动取 Config + Logger）
    $dp,                   // 数据
    'MeanRevStd',          // 策略别名
    [
        'initial_capital'  => 100_000,  // 可选：覆盖 trader 顶层配置
        'warmup_candles'   => 60,
    ]
);

// 3) 运行
//    交易对 + 周期在上面加载数据时已声明，run() 可零参数自动推导（等价于 run([BTC/USDT], '1h')）
$result = $backtest->run();

// 也可显式指定，例如只回测部分交易对或限定时间窗口：
// $result = $backtest->run([TradingSymbol::parse('BTC/USDT')], '1h', $fromMs, $toMs);

// 4) 看报表
$perf = new PerformanceReport($result, 100_000, 365);
echo "收益 {$perf->get('total_return_pct')}% · 夏普 {$perf->get('sharpe_ratio')}\n";
```

### loadDataProvider 决策逻辑

```
输入: exchange='binance', symbol='BTC/USDT', timeframe='1h', downloadOptions=[]
 │
 ├─ CSV 存在？ ──YES──→ 读 CSV → 塞 ArrayDataProvider → return
 │
 └─ NO
    │
    ├─ downloadOptions 非空 且 不含 dry_run？
    │   └─ YES → 自动调 TraderDownloadKlinesCommand::download() 下载
    │          → 下载完再读 → return
    │          → 下载失败 → RuntimeException（含手动下载命令行）
    │
    └─ （downloadOptions 为空 或 带了 dry_run）
        └─ RuntimeException（含手动下载指引 + 两种解决方式）
```

### loadDataProvider 参数说明

| 参数 | 类型 | 说明 |
|------|------|------|
| `$exchange` | `string` | 交易所名（binance / okx，小写） |
| `$symbol` | `string` | 标准交易对（BTC/USDT / BTC/USDT:SWAP / BTC/USD:QUARTER） |
| `$timeframe` | `string` | K 线周期（1m/5m/15m/30m/1h/4h/1d/1w） |
| `$downloadOptions` | `array` | 找不到 CSV 时自动下载的参数（可选）。支持键：days / from / to / retries / retry-base / dry-run |
| `$allowGaps` | `bool` | 传给 ArrayDataProvider 的 allowGaps（默认 false，严格校验时间间隔） |

### CSV 文件路径规则

```
RUNTIME_PATH/trader/data/{exchange}/{SYMBOL}_{TIMEFRAME}.csv

例：
  BTC/USDT       + 1h → binance/BTC-USDT_1h.csv
  BTC/USDT:SWAP  + 4h → binance/BTC-USDT-SWAP_4h.csv
```

> 文件名由 `KlinesCsvWriter::buildFilename()` 生成，非法字符 `/`、`:` 自动替换为 `-`。

### 1.2.1 多交易对批量加载：loadDataProviderBatch

**适用场景**：`Backtesting::run()` 支持多交易对组合回测（`array $symbols`），配合批量加载一次把多个 CSV 塞进**同一个** ArrayDataProvider。

**特点**：
- 每个 pair 独立判断 CSV 是否存在、是否需要下载，互不阻塞
- pair 级 `download` / `allowGaps` 可覆盖全局默认值
- 部分失败不影响已加载的 pair，错误消息里会给出 "完成 X/N" 汇总

```php
use App\Services\Trader\BacktestServiceProvider;
use App\Services\Exchanges\TradingSymbol;

// 简写：每个 pair 只有 symbol，统一用全局 downloadOptions
$dp = BacktestServiceProvider::loadDataProviderBatch('binance', '1h', [
    ['symbol' => 'BTC/USDT'],
    ['symbol' => 'ETH/USDT'],
    ['symbol' => 'BNB/USDT'],
], ['days' => 7]);

// 或显式指定每个 pair 的覆盖参数
$dp = BacktestServiceProvider::loadDataProviderBatch('binance', '1h', [
    ['symbol' => 'BTC/USDT'],                                       // 用全局 days=7
    ['symbol' => 'ETH/USDT',       'download' => ['days' => 30]],   // 单独 30 天
    ['symbol' => 'BTC/USDT:SWAP',  'allowGaps' => true],            // 覆盖全局 allowGaps
], ['days' => 7]);

// 跑多交易对回测：交易对和周期都已在 loadDataProviderBatch 声明，run() 零参数即可
$backtest = BacktestServiceProvider::newBacktestingByName(
    container(), $dp, 'MeanRevStd'
);
$result = $backtest->run();   // 自动推导：symbols=[BTC,ETH,BTC:SWAP]，timeframe=1h

// 如需只回测其中部分交易对，或限定时间窗口，仍可显式传参：
// $result = $backtest->run(
//     [TradingSymbol::parse('BTC/USDT'), TradingSymbol::parse('ETH/USDT')],
//     '1h', $fromMs, $toMs
// );
```

**`run()` 参数自动推导规则**（全部参数可选，向后兼容）：

| 参数 | 省略时（null）的行为 |
|------|---------------------|
| `$symbols` | 取 `DataProvider::getAvailableSymbols()`，即全部已加载交易对 |
| `$timeframe` | 取 `DataProvider::getAvailableTimeframes()`：**仅 1 个周期**时自动使用；**多个周期**时抛 `InvalidArgumentException` 要求显式指定（避免歧义）；**0 个**（空 provider）抛异常 |
| `$fromMs / $toMs` | 默认 `null`，即回测全部已加载时间范围 |

> 提示：`loadDataProvider` / `loadDataProviderBatch` 一次只加载一个周期，所以正常流程下 `run()` 零参数即可；只有手动往同一个 DataProvider 塞了多个周期时，才需要显式指定 timeframe。

**参数说明**：

| 参数 | 类型 | 说明 |
|------|------|------|
| `$exchange` | `string` | 交易所名（统一前缀，全部 pair 走同一交易所）|
| `$timeframe` | `string` | K 线周期（统一）|
| `$pairs` | `list<array>` | 每个 pair 的配置。最小 `['symbol' => 'BTC/USDT']`，可选 `download`（覆盖全局下载参数）和 `allowGaps`（覆盖全局）|
| `$downloadOptions` | `array` | 全局默认下载参数（pair 级 `download` 覆盖）|
| `$allowGaps` | `bool` | 全局默认 allowGaps（pair 级覆盖）|

**错误消息示例**（部分 pair 失败时）：

```
[loadDataProviderBatch] 完成 2/3，错误：
  • pairs[1] THIS_PAIR_DOES_NOT_EXIST/NOTHING: [loadDataProvider] CSV 不存在：...
```

---

## 1.3 方式二：createArrayDataProvider + 手动加载 + newBacktestingByName

**适用场景**：数据来自数据库、API、或其他自定义来源（非 CSV 文件）。

```php
use App\Services\Trader\BacktestServiceProvider;
use App\Services\Trader\Market\Candle;
use App\Services\Exchanges\TradingSymbol;

// 1) 创建空的 DataProvider
$dp = BacktestServiceProvider::createArrayDataProvider();

// 2) 从你的数据源构造 Candle[] 并塞入
$candles = [];
foreach ($rows as $r) {
    $candles[] = Candle::fromArray([
        'timestamp' => (int) $r['ts'],
        'open'      => (float) $r['o'],
        'high'      => (float) $r['h'],
        'low'       => (float) $r['l'],
        'close'     => (float) $r['c'],
        'volume'    => (float) $r['v'],
    ]);
}
$symbol = TradingSymbol::parse('BTC/USDT');
$dp->setCandles($symbol, '5m', $candles, true);  // true = 允许 K 线缺口

// 3) 用别名装配 + 运行
$backtest = BacktestServiceProvider::newBacktestingByName(
    container(), $dp, 'MeanRevStd'
);
$result = $backtest->run([$symbol], '5m');
```

> **Candle 构造校验**：high ≥ max(open, close, low)，low ≤ min(open, close, high)，volume ≥ 0，timestamp > 0。

---

## 1.4 方式三：createStrategyByName + newBacktesting

**适用场景**：需要拿到策略实例做额外操作（如 `var_dump` 参数、GridSearch 调参、动态修改属性）。

```php
use App\Services\Trader\BacktestServiceProvider;

$cfg = config('trader');  // 或自己拼数组

// 用别名创建策略（可覆盖构造参数）
$strategy = BacktestServiceProvider::createStrategyByName($cfg, 'MeanRevStd');

// GridSearch：临时改构造参数跑多组
$strategy2 = BacktestServiceProvider::createStrategyByName(
    $cfg,
    'MeanRevStd',
    [20, 1.9, 14, 32.0, 67.0, 0.75]  // ← 第 3 参数 override construct
);

// 再用 newBacktesting 装配
$backtest = BacktestServiceProvider::newBacktesting(
    container(), $dp, $strategy
);
```

---

## 1.5 方式四：纯数组配置 build（无容器 / 单元测试）

**适用场景**：单元测试、无容器环境、隔离测试。

```php
use App\Services\Trader\BacktestServiceProvider;
use App\Services\Trader\Strategies\BollingerRsiMeanReversionStrategy;

$cfg = [
    'stake_currency' => 'USDT',
    'initial_capital' => 10_000,
    'warmup_candles' => 30,
    'fee'        => ['maker_rate' => 0, 'taker_rate' => 0],
    'slippage'   => ['default_pct' => 0, 'pair_overrides' => []],
    'protection' => ['default_cooling_ms' => 0, 'by_exit_reason' => []],
    'strategies' => [
        'MeanRevStd' => [
            'class'     => BollingerRsiMeanReversionStrategy::class,
            'construct' => [20, 2.0, 14, 35.0, 65.0, 0.5],
        ],
    ],
];

$strategy = BacktestServiceProvider::createStrategyByName($cfg, 'MeanRevStd');
$backtest = BacktestServiceProvider::build($cfg, $dp, $strategy, null);  // logger = null
$result = $backtest->run([TradingSymbol::parse('BTC/USDT')], '5m');
```

---

## 1.6 策略别名注册表

在 [config/trader.php](file:///Users/wmc/data/trae/project/whale/config/trader.php) 的 `strategies` 键注册：

```php
'strategies' => [
    // 格式 A（简化）：无构造参数 / 用类默认构造参数
    'EmaCrossDefault' => \App\Services\Trader\Strategies\EmaCrossStrategy::class,

    // 格式 B（带构造参数）：class + construct 数组
    'MeanRevStd' => [
        'class'     => \App\Services\Trader\Strategies\BollingerRsiMeanReversionStrategy::class,
        'construct' => [20, 2.0, 14, 30.0, 65.0, 0.8],
    ],

    // 同一策略类可注册多个别名 → 对应不同参数组
    'MeanRevConservative' => [
        'class'     => \App\Services\Trader\Strategies\BollingerRsiMeanReversionStrategy::class,
        'construct' => [20, 2.2, 14, 25.0, 70.0, 1.1],
    ],
],
```

**错误提示**：如果别名不存在，异常消息会直接列出当前已注册的所有别名：

```
策略 'DoesNotExist' 未找到。请在 config/trader.php 的 strategies 里注册，或使用完整类名。
当前已注册策略: [MeanRevStd, EmaCross20_50, MeanRevConservative]
```

---

## 1.7 运行结果分析

```php
use App\Services\Trader\PerformanceReport;
use App\Services\Trader\ResultExporter;

// 绩效指标
$perf = new PerformanceReport($result, $initialCapital, $days);
echo '总收益率 ' . $perf->get('total_return_pct') . "%\n";
echo '夏普比率 ' . $perf->get('sharpe_ratio') . "\n";
echo '最大回撤 ' . $perf->get('max_drawdown_pct') . "%\n";
echo '胜率     ' . $perf->get('win_rate_pct') . "%\n";

// 导出
$exporter = new ResultExporter();
file_put_contents(RUNTIME_PATH . '/bt.json', $exporter->toJson($result, $perf));
$exporter->writeCsvFile(
    RUNTIME_PATH . '/bt_trades.csv',
    $exporter->toCsvRows($result)
);
```

---

# 二、回测原理

## 2.1 整体架构

```
          ┌────────────────────────── 策略代码只写这些 ───────────────────────┐
          │ populateIndicators → populateEntryTrend → populateExitTrend     │
          │ (计算指标)        (写入 enter_long)       (写入 exit_long)        │
          └────────────────────────────────────────────────────────────────────┘
                                        ▲
                                        │ 12 列信号矩阵（SignalCols 索引下标访问）
                                        │
          ┌───────────────────────────────────────────────────────────────────┐
          │ Backtesting.php (orchestrator 编排器)                            │
          │  ① 读 DataProvider → ② 策略三步法预计算                           │
          │  ③ 逐 K 线推进（warmup 前忽略）                                   │
          │     a) 对 open trades 先做 checkTradeExit（平仓优先于开仓）         │
          │     b) ProtectionManager 校验准入（开仓上限/冷却锁）                │
          │     c) executeEntry（next-bar-open 成交，防前视）                  │
          │     d) 拍 WalletSnapshot（权益曲线）                               │
          │  ④ 强制平所有未平仓 → BacktestResult                               │
          └───────────────────────────────────────────────────────────────────┘
                 ▲             ▲              ▲            ▲
                 │             │              │            │
         DataProvider   MatchingEngine   ExitRules   ProtectionManager
         (可替换: 回测=内存) (撮合+钱包变动)  (规则匹配)    (准入+冷却)
```

**核心设计决策**：

| 决策 | 设计 | 原因 |
|-----|------|-----|
| 入场执行价 | 第 i 根 close 信号，第 i+1 根 open 成交 | 避免"在 K 线 close 看到 MACD 交叉后用同一根 close 买入" |
| 止损判定价 | low / high 影线 | 止损被影线扫是实盘常态，只用 close 会严重低估止损触发 |
| 止损成交价 | 用 stopPrice（不是 low/high/close） | 限价止损单挂在 stopPrice，价格刚触达即成交 |
| ROI / exit_signal 判定 | 用 close | 保守：收盘确认趋势后再出场 |
| 手续费 | maker/taker 分开算，按订单类型 | 一次 maker-taker 差异 = 回测盈亏差 2~3 倍 |
| 同一策略可在回测/实盘运行 | DataProvider 可替换 | 一套策略代码两种运行模式，减少漂移 |

---

## 2.2 信号矩阵：策略与引擎的通信协议

策略三步法的输入和输出都是"12 列 + 自定义扩展列"的 PHP 数组。

| 列下标 | SignalCols 常量 | 含义 | 类型 |
|-------|-----------------|------|-----|
| 0 | `DATE` | 毫秒时间戳 `int` | 1_700_000_000_000 |
| 1 | `OPEN` | 开盘价 `float` | |
| 2 | `HIGH` | 最高价 `float` | **止损/止盈判断必须用** |
| 3 | `LOW` | 最低价 `float` | **止损/止盈判断必须用** |
| 4 | `CLOSE` | 收盘价 `float` | |
| 5 | `VOLUME` | 成交量 `float` | |
| 6 | `ENTER_LONG` | 入场 LONG 强度 | 0 无 / 1 普通 / 2 强制入场（绕过冷却锁） |
| 7 | `EXIT_LONG` | 出场 LONG | 0 无 / 1 出场 |
| 8 | `ENTER_SHORT` | 入场 SHORT（仅期货/杠杆） | 0/1/2 |
| 9 | `EXIT_SHORT` | 出场 SHORT | 0/1 |
| 10 | `ENTER_TAG` | 入场标签 `string` | `ema_cross_up(rsi<30)` 方便报表分析 |
| 11 | `EXIT_TAG` | 出场标签 `string` | |

自定义列（RSI、EMA 等）写在 `SignalCols::NUM_COLUMNS`（=12）之后，用 `12 + n` 下标访问。

---

## 2.3 策略开发三步法

任何策略都必须实现 `StrategyInterface`（或继承 `AbstractStrategy`），覆盖三步：

1. **populateIndicators($matrix, $symbol, $timeframe)**：计算指标
   - 输入：12 列基础 OHLCV 筩阵
   - 输出：附加了 RSI/EMA/布林带等自定义列的新矩阵
2. **populateEntryTrend($matrix)**：写入 `enter_long/enter_short/enter_tag`
3. **populateExitTrend($matrix)**：写入 `exit_long/exit_short/exit_tag`

> ⚠️ 三个方法都必须"纯函数"：只读入参，返回新数组，不得引用赋值修改。

### 完整示例：EmaCrossStrategy

```php
class EmaCrossStrategy extends AbstractStrategy
{
    protected $stoploss             = 0.03;       // 3% 固定止损
    protected $minimalRoi           = [0 => 0.03, 60 => 0.02, 180 => 0.01, 360 => 0];
    protected $defaultStakeAmount   = 500.0;
    protected $maxOpenTrades        = 5;
    protected $maxOpenTradesPerPair = 1;

    public function populateIndicators(array $matrix, TradingSymbol $symbol, string $timeframe): array
    {
        $closeArr = array_column($matrix, SignalCols::CLOSE);
        $emaS = self::ema($closeArr, 20);
        $emaL = self::ema($closeArr, 50);
        foreach ($matrix as $i => &$row) {
            $row[self::COL_EMA_SHORT] = $emaS[$i];
            $row[self::COL_EMA_LONG]  = $emaL[$i];
        }
        return $matrix;
    }

    public function populateEntryTrend(array $matrix): array
    {
        for ($i = 1; $i < count($matrix); $i++) {
            if ($matrix[$i-1][self::COL_EMA_SHORT] <= $matrix[$i-1][self::COL_EMA_LONG]
             && $matrix[$i  ][self::COL_EMA_SHORT] >  $matrix[$i  ][self::COL_EMA_LONG]
             && $matrix[$i][SignalCols::CLOSE] > $matrix[$i][self::COL_EMA_LONG] * 1.003)
            {
                $matrix[$i][SignalCols::ENTER_LONG] = SignalCols::SIG_NORMAL;
                $matrix[$i][SignalCols::ENTER_TAG]  = 'ema_cross_up';
            }
        }
        return $matrix;
    }

    public function populateExitTrend(array $matrix): array { /* 死叉镜像 */ }
}
```

### 钩子方法

```php
// 自定义入场价（null = 使用默认 next-bar open）
public function customEntryPrice($symbol, $side, $row, $prev): ?float
{
    return (float) $prev[SignalCols::LOW] * 0.999;  // 挂到前一根 low - 0.1%
}

// 自定义平仓（每根 K 线在其他 exit 规则之前调用）
public function customExit(TradeRecord $trade, int $idx, array $row): bool
{
    $holdMinutes = max(0, (int) floor(($row[SignalCols::DATE] - $trade->getOpenTimestamp()) / 60_000));
    if ($holdMinutes > 24 * 60) {  // 持仓超 24 小时强制平
        return true;
    }
    return false;
}
```

---

## 2.4 逐 K 线推进主循环

`Backtesting::run()` 的核心流程：

```
run(array $symbols, string $timeframe): BacktestResult

1. 校验 warmup：DataProvider 至少要有 warmup_candles + 1 根 K 线
2. 对每个 symbol 调策略三步法 → 得到完整信号矩阵（预计算，不在循环里重算）
3. 逐 K 线推进（rowI 从 warmupCandles 开始）：
   a) processExitsForPair：对已有持仓先检查平仓（平仓优先于开仓）
      → 更新 high/low/close → 更新 trailing stop
      → MatchingEngine::checkTradeExit() → executeExit()
   b) 记录当前 quote price（Wallet 快照用）
   c) processEntriesForPair：检查入场信号
      → 信号在 row[i] → 执行在 row[i+1].open（防前视）
      → ProtectionManager 校验准入
      → MatchingEngine::executeEntry()
   d) 定期 prune protection（清理过期冷却锁）
   e) 拍 WalletSnapshot（权益曲线数据点）
4. 回测结束 → 强制平所有未平仓 → 生成 BacktestResult
```

---

## 2.5 防前视偏差核心机制

### 入场：信号 i → 执行 i+1 根 open

```
K 线序列： 0 — 1 — 2 — 3 — ... — i — i+1（open 在这里成交）
                          ▲
                          signal at close (i)
```

在 `processEntriesForPair()` 里：

```php
if ($rowI + 1 >= count($matrix)) break;   // 最后一根不能买（没有 i+1 执行 bar）
$execRow = $matrix[$rowI + 1];
```

### 平仓：止损/止盈用 high/low 判定 + 固定价成交

| ExitType | 判定条件 (long) | 成交价 |
|----------|----------------|--------|
| STOP_LOSS / TRAILING / LIQUIDATION | low ≤ stopPrice | stopPrice（夹逼到 [low, high]） |
| ROI / EXIT_SIGNAL / CUSTOM_EXIT / HOLD_TIMEOUT | close ≥ target | close（夹逼） |

> **为什么不用 low 成交？** 当 long 止损 stop=48500，low=47500 时，止损单挂在 48500 恰好成交，而不是用 low=47500 成交（否则夸大亏损）。

---

## 2.6 平仓优先级顺序

同 Freqtrade，从高到低：

```
1. LIQUIDATION  （强平，优先级最高）
2. STOP_LOSS    （固定止损）
3. TRAILING     （追踪止损，达到 trailingStopPositive 后才启用）
4. ROI          （最小 ROI 阶梯，分钟 × 收益率）
5. CUSTOM_EXIT  （策略回调 customExit()）
6. EXIT_SIGNAL  （exit_long/exit_short 列）
7. FORCE_EXIT   （回测结束后未平仓强制平，不在主循环里）
8. HOLD_TIMEOUT （超时 maxHoldBars）
```

---

## 2.7 风控配置：止损 / ROI / 追踪止损 / HOLD 超时

都在 `AbstractStrategy` 里以 `protected` 属性呈现，子类覆写即可：

| 属性 | 类型 | 示例 | 含义 |
|-----|------|-----|-----|
| `$stoploss` | float 小数 | 0.03 | 固定止损百分比，0 = 不启用 |
| `$minimalRoi` | `[分钟int => 小数收益率]` | `[0=>0.03, 60=>0.02, 180=>0.01, 360=>0]` | 开仓 0 分钟内盈 3% 就走；60 分钟后 2%；180m 后 1%；360m 后持平就走 |
| `$trailingStop` | float | 0.03 | 追踪止损 3%（long = close × 0.97），0 = 不启用 |
| `$trailingStopPositive` | float | 0.015 | 先达到 1.5% 未实现盈再启动 trailing，0 = 立即启用 |
| `$maxHoldBars` | int | 0 | 超过 N 根 K 线强制平（0 = 不限制） |
| `$defaultStakeAmount` | float | 500.0 | 每笔默认投入 |
| `$maxOpenTrades` | int | 5 | 全局最大同时持仓数 |
| `$maxOpenTradesPerPair` | int | 1 | 每个 pair 最大持仓数 |
| `$enableShort` | bool | false | 是否允许做空（现货安全设 false） |

> **ROI 表的分钟数按真实时钟 `now - openTs` 算**，不是按 row 序号差，这样即使有 K 线缺口也能对齐。

---

## 2.8 保护机制：冷却锁 / 开仓上限

ProtectionManager 在入场前统一检查，通过就执行，不通过就计入 `rejected_signals`：

| 规则 | 来源 | 说明 |
|-----|------|-----|
| 全局最大开仓数 | `$strategy->maxOpenTrades` | 未平仓总数 ≥ max → 拒绝 |
| 每 pair 最大开仓数 | `$strategy->maxOpenTradesPerPair` | 同 pair 持仓超限 → 拒绝 |
| Pair 冷却锁 | config `trader.protection` | 平仓后按原因冷却一段时间 |

配置示例：

```php
'protection' => [
    'default_cooling_ms' => 600_000,  // 10 分钟默认
    'by_exit_reason' => [
        'stop_loss' => 3_600_000,     // 被止损 → 冷却 1 小时
        'roi'       => 0,             // ROI 止盈后可立即开
        '*'         => 300_000,       // 其他原因 5 分钟
    ],
],
```

强制入场（signal=2）可跳过冷却锁（仍受 maxOpenTrades 上限约束）：

```php
$matrix[$i][SignalCols::ENTER_LONG] = SignalCols::SIG_FORCE;
```

---

# 三、指标计算系统（IndicatorCalculator）

> 源码：[IndicatorCalculator.php](file:///Users/wmc/data/trae/project/whale/app/Services/Trader/Strategy/IndicatorCalculator.php)

## 3.1 为什么用 IndicatorCalculator

| 对比项 | 手写 PHP 算法 | IndicatorCalculator |
|-------|-------------|-------------------|
| **速度** | PHP 循环计算 | C 扩展（快 10~30 倍），百万 K 线回测差距巨大 |
| **正确性** | 需自行验证 | 与 TradingView / Binance / TA-Lib 严格对齐，62 个单元测试验证解析解 |
| **契约** | 无保证 | 所有方法 `count($out) === count($in)`，key 从 `0..N-1` 连续 |
| **坑点** | 需自行踩坑 | 横盘 RSI=50、stddev 无溢出、AroonOsc 方向不反——已内置修复 |

---

## 3.2 前置依赖 & 精度配置

| 项 | 说明 |
|----|------|
| **扩展要求** | 必须安装 PHP `trader` 扩展。`BacktestServiceProvider::build()` 每次装配都会调用 `requireTraderExtension()` 检查 |
| **安装命令** | `pecl install trader` |
| **精度配置** | 首次调用自动 `ini_set('trader.real_precision', '10')`（默认 3 会丢精度）。**不要设为 -1**（会让所有指标返回 0） |
| **无扩展时** | 测试自动 `markTestSkipped`，不阻塞 CI；运行时抛 `RuntimeException` |

---

## 3.3 契约保证（通用行为）

所有 33 个方法均满足：

1. **长度一致性**：`count(xxx($src, ...)) === count($src)`，warmup 不足用种子值填充
2. **索引连续性**：key 一定是 `range(0, N-1)`（原生 `trader_*` 会从 `period-1` 开始跳过前半段）
3. **无 NAN / INF**：对原生返回的 NAN 做防御性替换（振荡类填 0/50，波动率类填 0）
4. **warmup 种子策略**：
   - RSI / MFI / STOCH 家族 → 中性值 **50**
   - CMO / CCI / APO / PPO / ROC / MOM / ADXR → **0**
   - MA 家族 → 前 `i+1` 根平均
   - AROON 家族 → **50**
5. **边界输入**：空数组、1 根、period=1 等场景不抛异常、不返回 NAN/INF

---

## 3.4 33 个指标速查表

### A. 均线类（Moving Averages，9 个）

| 方法 | 签名 | 常用用法 & 阈值 |
|------|------|---------------|
| `sma()` | `sma(c, int p): float[]` | 基准线；`close > SMA(200)` 判定长期趋势 |
| `ema()` | `ema(c, int p): float[]` | 响应比 SMA 快；金叉死叉核心 |
| `wma()` | `wma(c, int p): float[]` | 越近权重越大（线性 1…p），去噪友好 |
| `dema()` | `dema(c, int p): float[]` | 双 EMA（2·EMA − EMA(EMA)），滞后更小 |
| `tema()` | `tema(c, int p): float[]` | 三 EMA，进一步去滞后，超短趋势捕捉 |
| `trima()` | `trima(c, int p): float[]` | 三角 MA（SMA×2），重心平滑，抗毛刺 |
| `kama()` | `kama(c, int p): float[]` | 考夫曼自适应 MA（震荡慢、趋势快），p=10/30 |
| `ma()` | `ma(c, int p, int maType): float[]` | 通用 MA：传 `TRADER_MA_TYPE_*` 常量 |
| `bbands()` | `bbands(c, p, up=2.0, dn=2.0): [upper,mid,lower]` | 布林带三件套；**突破 lower + RSI<30 = 均值回归入场** |

### B. 动量 / 震荡类（Momentum & Oscillators，13 个）

| 方法 | 签名 | 值域 | 超买 | 超卖 | 说明 |
|------|------|------|------|------|------|
| `rsi()` | `rsi(c, p=14)` | [0,100] | >70 | <30 | Wilder 平滑；**横盘自动返回 50** |
| `cmo()` | `cmo(c, p=14)` | [-100,+100] | >+50 | <−50 | 钱德动量 |
| `roc()` | `roc(c, p=12): %` | ±∞ % | >+10% | <−10% | 变动率 |
| `mom()` | `mom(c, p=10)` | 价差 ±∞ | >0 偏多 | <0 偏空 | 动量绝对差 |
| `willr()` | `willr(h,l,c, p=14)` | [-100,0] | >−20 | <−80 | Williams %R |
| `cci()` | `cci(h,l,c, p=20)` | ±∞ | >+100 | <−100 | 顺势指标 |
| `stoch()` | `stoch(h,l,c, fK=14, sK=3, sD=3): [K,D]` | [0,100] | K>80 | K<20 | 慢速 KDJ；金叉 K 上穿 D + K<20 |
| `stochf()` | `stochf(h,l,c, fK=14, fD=3): [fastK, fastD]` | [0,100] | >80 | <20 | 快速随机 |
| `stochRsi()` | `stochRsi(c, rsiP=14, fK=5, fD=3): [K,D]` | [0,100] | >80 | <20 | 对 RSI 再算 Stochastic |
| `ultOsc()` | `ultOsc(h,l,c, 7,14,28)` | [0,100] | >70 | <30 | 终极振荡器，三周期加权 |
| `apo()` | `apo(c, fast=12, slow=26, ma=EMA)` | ±∞ 价差 | >0 偏多 | <0 偏空 | MA 绝对差 |
| `ppo()` | `ppo(c, fast=12, slow=26, ma=EMA): %` | ±∞ % | >+1% | <−1% | **百分比**差；跨品种可比 |
| `macd()` | `macd(c, fast=12, slow=26, signal=9): [macd, signal, hist]` | ±∞ | DIF 上穿 DEA = 金叉 | DIF 下穿 DEA = 死叉 | 三线：DIF / DEA / HIST |

### C. 波动率类（Volatility，5 个）

| 方法 | 签名 | 值域 | 常用用法 |
|------|------|------|--------|
| `stddev()` | `stddev(c, p): float[]` | ≥ 0 | 滚动样本标准差（无偏 ÷(n-1)） |
| `variance()` | `variance(c, p): float[]` | ≥ 0 | 方差 = σ² |
| `trange()` | `trange(h,l,c): float[]` | ≥ 0 | True Range 单根真实波幅 |
| `atr()` | `atr(h,l,c, p=14): float[]` | ≥ 0 | ATR（Wilder）；**止损距离 = k×ATR（1.5~3）** |
| `natr()` | `natr(h,l,c, p=14): float[] → %` | ≥ 0 % | 归一化 ATR；跨品种可比 |

### D. 趋势方向 / 强度类（Trend，7 个）

| 方法 | 签名 | 值域 | 常用阈值 & 用法 |
|------|------|------|--------------|
| `adx()` | `adx(h,l,c, p=14)` | [0,100] | **>25 有趋势**、>50 强趋势；**<20 禁止趋势型策略开仓** |
| `adxr()` | `adxr(h,l,c, p=14)` | [0,100] | ADX 的跨度平均（更稳定） |
| `plusDi()` | `plusDi(h,l,c, p=14)` | [0,100] | `+DI` 上穿 `-DI` + ADX>25 = DI 金叉做多 |
| `minusDi()` | `minusDi(h,l,c, p=14)` | [0,100] | 组合 `+DI / -DI / ADX` → DMI 三件套 |
| `aroon()` | `aroon(h,l, p=14): [up, down]` | [0,100] | **Up>70 且 Down<30 = 多头趋势** |
| `aroonOsc()` | `aroonOsc(h,l, p=14)` | [-100,+100] | Up − Down；**>0 偏多、<0 偏空** |
| `sar()` | `sar(h,l, step=0.02, max=0.2): float[]` | 价格同量纲 | 抛物线追踪价；**close > SAR 做多** |

### E. 量价类（Volume-Price，4 个）

| 方法 | 签名 | 值域 | 常用用法 |
|------|------|------|--------|
| `mfi()` | `mfi(h,l,c,v, p=14)` | [0,100] | RSI 的带量版；**价格新高 + MFI 未新高 = 顶背离** |
| `obv()` | `obv(c, v): float[]` | 累计量 ±∞ | 价格新高 + OBV 未新高 = 顶背离 |
| `ad()` | `ad(h,l,c,v): float[]` | 累计 ±∞ | Chaikin A/D Line |
| `adOsc()` | `adOsc(h,l,c,v, fast=3, slow=10): float[]` | ±∞ | A/D 的 EMA 差；短期资金流向加速度 |

### F. 典型价 / 价格合成（4 个）

| 方法 | 签名 | 用途 |
|------|------|------|
| `avgPrice()` | `avgPrice(o,h,l,c): float[]` | 均价 = (O+H+L+C)/4 |
| `typPrice()` | `typPrice(h,l,c): float[]` | 典型价 = (H+L+C)/3；CCI / MFI 内部用 |
| `wclPrice()` | `wclPrice(h,l,c): float[]` | 加权收盘 = (H+L+2C)/4 |
| `medPrice()` | `medPrice(h,l): float[]` | 中间价 = (H+L)/2 |

---

## 3.5 使用场景与组合策略

### 场景一：趋势跟踪（MACD + ADX 过滤 + ATR 止损）

**适用**：单边趋势行情。ADX > 25 确认有趋势时才开仓，MACD 金叉入场，ATR 动态止损。

```php
use App\Services\Trader\Strategy\IndicatorCalculator as Ind;

// 趋势确认
$adx = Ind::adx($high, $low, $close, 14);       // ADX > 25 才开仓
// 入场信号
[$macdLine, $sigLine, $hist] = Ind::macd($close, 12, 26, 9);  // 金叉入场
// 动态止损
$atr = Ind::atr($high, $low, $close, 14);        // 止损 = entryPrice - 2×ATR
```

### 场景二：均值回归（布林带 + RSI + 量能过滤）

**适用**：震荡行情。价格跌破布林带下轨 + RSI 超卖 + 放量时入场，回归中轨出场。

```php
// 布林带
[$bbUpper, $bbMid, $bbLower] = Ind::bbands($close, 20, 2.0, 2.0);
// RSI 超卖
$rsi = Ind::rsi($close, 14);                      // RSI < 30 入场
// 量能过滤
$volSma = Ind::sma($volume, 20);                  // volume ≥ VOL_SMA × 0.8

// 入场条件：close ≤ BB_LOWER && RSI < 30 && volume ≥ VOL_SMA × 0.8
// 出场条件：close ≥ BB_MID 或 RSI ≥ 65
```

> 完整实现见 [BollingerRsiMeanReversionStrategy.php](file:///Users/wmc/data/trae/project/whale/app/Services/Trader/Strategies/BollingerRsiMeanReversionStrategy.php)。

### 场景三：顶底背离检测（MFI / OBV）

**适用**：判断趋势是否即将反转。价格创新高但指标未创新高 → 顶背离。

```php
$mfi = Ind::mfi($high, $low, $close, $volume, 14);  // RSI 的带量版
$obv = Ind::obv($close, $volume);                   // 能量潮

// 顶背离：price 创新高但 MFI / OBV 未创新高 → 警惕反转
// 底背离：price 创新低但 MFI / OBV 未创新低 → 可能反弹
```

### 场景四：多指标组合过滤

**适用**：降低假信号。多个指标从不同维度（趋势、动量、波动率、量价）交叉确认。

| 维度 | 指标 | 过滤目的 |
|------|------|---------|
| 趋势 | ADX > 25 | 排除无趋势震荡行情 |
| 动量 | MACD 金叉 | 确认趋势方向 |
| 波动率 | ATR > NATR | 确认波动足够（非横盘） |
| 量价 | volume > VOL_SMA | 确认量能支撑 |

---

## 3.6 三步上手示例

```php
use App\Services\Trader\Strategy\IndicatorCalculator as Ind;
use App\Services\Trader\Strategy\SignalCols;

// Step 1：用 array_column 抽出 OHLCV 列
$close  = array_column($matrix, SignalCols::CLOSE);
$high   = array_column($matrix, SignalCols::HIGH);
$low    = array_column($matrix, SignalCols::LOW);
$volume = array_column($matrix, SignalCols::VOLUME);

// Step 2：调用 IndicatorCalculator（全部是 static 方法）
[$macdLine, $sigLine, $hist] = Ind::macd($close, 12, 26, 9);
$adx = Ind::adx($high, $low, $close, 14);
$atr = Ind::atr($high, $low, $close, 14);

// Step 3：回填到 $matrix 扩展列 + 写 enter/exit 信号
// 用 const 声明列下标（避免魔法数字）
// protected const COL_MACD = SignalCols::NUM_COLUMNS + 0;
// protected const COL_SIG  = SignalCols::NUM_COLUMNS + 1;
// protected const COL_ADX  = SignalCols::NUM_COLUMNS + 2;
// protected const COL_ATR  = SignalCols::NUM_COLUMNS + 3;

foreach ($matrix as $i => &$row) {
    $row[self::COL_MACD] = $macdLine[$i];
    $row[self::COL_SIG]  = $sigLine[$i];
    $row[self::COL_ADX]  = $adx[$i];
    $row[self::COL_ATR]  = $atr[$i];
}
unset($row);

// 入场：MACD 金叉 + ADX > 25
for ($i = 1; $i < count($matrix); $i++) {
    $prev = $matrix[$i - 1];
    $cur  = $matrix[$i];
    $crossUp = $prev[self::COL_MACD] <= $prev[self::COL_SIG]
            && $cur[self::COL_MACD] >  $cur[self::COL_SIG];
    if ($crossUp && $cur[self::COL_ADX] > 25) {
        $matrix[$i][SignalCols::ENTER_LONG] = SignalCols::SIG_NORMAL;
        $matrix[$i][SignalCols::ENTER_TAG]  = sprintf('macd_cross(adx=%.1f)', $cur[self::COL_ADX]);
    }
}
```

> **好习惯**：把 IndicatorCalculator 调用全部放在 `populateIndicators()` 里，后续 `populateEntryTrend / populateExitTrend` 只读扩展列，不再重复计算。

### 参数速查（与 TradingView 对齐）

| 指标 | 默认参数 | 常用替代 |
|------|---------|---------|
| RSI | 14 | 短 9 / 保守 21 |
| MACD | 12, 26, 9 | 4h 短线 5/35/5 |
| BBands | 20, 2.0σ | 保守 2.2σ / 激进 1.8σ |
| ATR | 14 | 短 7 / 长 20 |
| ADX | 14 | 快 7 |
| Stoch (KDJ) | 14, 3, 3 | 日内 9/3/3 |

---

## 3.7 已知坑点与内置修复

| # | 现象 | 根因 | 修复方式 |
|---|------|------|---------|
| 1 | BBands/ATR 只显示 3 位小数 | `trader.real_precision = 3` | 自动 `ini_set(..., '10')`；**不要设 -1** |
| 2 | `trader_stddev()` 返回溢出垃圾值 | 某些 PHP 版本的 bug | 用 BBands 反推 + `×√(n/(n-1))` 校正 |
| 3 | 横盘时 `trader_rsi()` 返回 0 | 0/0 边界未处理 | 检测横盘窗口 → 强制修正为 50 |
| 4 | `trader_aroonosc()` 方向反了 | 原生公式为 Down−Up（行业标准是 Up−Down） | 不调原生，用 aroon() 的 Up−Down 重算 |

---

# 附录

## A. 目录与模块总览

```
app/Services/Trader/
├── Backtesting.php               # 回测编排器（主入口）
├── BacktestResult.php            # 回测结果值对象
├── BacktestServiceProvider.php   # 工厂：按配置装配 + loadDataProvider
├── MatchingEngine.php            # 撮合核心：executeEntry / executeExit
├── PerformanceReport.php         # 绩效指标（夏普/索提诺/卡玛/最大回撤/胜率）
├── ResultExporter.php            # 导出 JSON / CSV

├── Market/                       # 行情层
│   ├── Candle.php                # K 线值对象（OHLCV + 毫秒时间戳，不可变）
│   ├── Timeframe.php             # 周期常量 + 毫秒/间隔映射
│   ├── DataProviderInterface.php # 数据接口（回测/实盘共用）
│   ├── ArrayDataProvider.php     # 内存数据提供者（回测默认）
│   ├── KlinesCsvWriter.php       # CSV 写工具
│   └── KlinesCsvReader.php       # CSV 读工具

├── Enum/                         # 强类型字符串枚举
├── ExitRules/                    # 平仓规则引擎
├── Fee/                          # 手续费 / 滑点
├── Model/                        # 领域模型（Order / Trade / Wallet）
├── Protection/                  # 保护 / 风险控制
├── Strategy/
│   ├── SignalCols.php            # 12 列信号矩阵常量
│   ├── StrategyInterface.php     # 策略接口契约
│   ├── AbstractStrategy.php      # 抽象基类（风控属性都在这）
│   └── IndicatorCalculator.php   # 33 个技术指标（封装 trader C 扩展）
└── Strategies/                   # 示例策略
    ├── EmaCrossStrategy.php
    └── BollingerRsiMeanReversionStrategy.php
```

---

## B. 默认配置 config/trader.php

完整键清单见 [config/trader.php](file:///Users/wmc/data/trae/project/whale/config/trader.php)。

| 键 | .env | 默认 | 说明 |
|----|------|------|-----|
| `stake_currency` | `TRADER_STAKE_CURRENCY` | USDT | 计价货币 |
| `initial_capital` | `TRADER_INITIAL_CAPITAL` | 10000 | 初始资金 |
| `run_mode` | `TRADER_RUN_MODE` | backtest | backtest / dry_run / live |
| `trading_mode` | `TRADER_TRADING_MODE` | spot | spot / margin / futures |
| `timeframe` | `TRADER_TIMEFRAME` | 5m | 默认周期 |
| `warmup_candles` | `TRADER_WARMUP_BARS` | 300 | 预热 K 线数 |
| `data_dir` / `output_dir` | - | runtime/trader/{data,output} | 数据 & 导出目录 |
| `fee.maker_rate` | `TRADER_FEE_MAKER` | 0.0002 | 挂单费率 |
| `fee.taker_rate` | `TRADER_FEE_TAKER` | 0.0004 | 吃单费率 |
| `slippage.default_pct` | `TRADER_SLIPPAGE_DEFAULT` | 0.001 | 默认滑点 |
| `protection.default_cooling_ms` | `TRADER_DEFAULT_COOLING_MS` | 0 | 默认冷却毫秒 |

FeeCalculator 工厂：

```php
FeeCalculator::binanceSpot();     // maker 0.1%, taker 0.1%
FeeCalculator::binanceFutures();  // maker 0.02%, taker 0.04%
FeeCalculator::okxSpot();         // maker 0.08%, taker 0.1%
FeeCalculator::okxFutures();      // maker 0.02%, taker 0.05%
```

---

## C. PerformanceReport 30+ 指标定义

| 指标键 | 含义 |
|-------|------|
| `initial_capital` / `final_capital` | 起始 / 结束资金 |
| `total_net_profit` / `total_return_pct` | 绝对盈亏 / 收益率 |
| `cagr_pct` | 复合年化增长率 |
| `sharpe_ratio` | Sharpe = mean(日收益率) / std(日收益率) × √365 |
| `sortino_ratio` | Sortino：分母改为"下行波动率" |
| `calmar_ratio` | CAGR / max_drawdown_pct |
| `max_drawdown_pct / abs / peak / start / end` | 权益曲线最大回撤 |
| `total_trades` | 交易数（已平仓） |
| `signals_total` / `signals_rejected` | 总信号 / ProtectionManager 拒签数 |
| `win_count` / `loss_count` / `win_rate_pct` | 赢单/输单/胜率 |
| `avg_trade_profit_pct` | 平均单笔收益率 |
| `avg_win_profit_pct` / `avg_loss_profit_pct` | 平均赢/亏单 |
| `profit_loss_ratio` | 平均赢单% ÷ 平均亏单% |
| `profit_factor` | 赢单$ / 亏单$（绝对值） |
| `best_trade_pct` / `worst_trade_pct` | 最好/最差单笔 |
| `avg_duration_min` | 平均持仓分钟数 |
| `expectancy_per_trade_abs` | 数学期望每笔盈亏 |

---

## D. ResultExporter 导出 JSON / CSV

```php
$e = new ResultExporter();
$e->toArray($result, $perf);    // 扁平数组（API 返回）
$e->toJson($result, $perf);     // JSON 字符串（落盘/前端）
$e->toCsvRows($result);          // [header, ...rows]（Excel 友好）
$e->writeCsvFile('/path.csv', $rows);
```

---

## E. 单元测试覆盖清单

测试归属：`tests/trader_test/`（命名空间 `Sikelan\Tests\trader_test`）。

```bash
./vendor/bin/phpunit tests/trader_test/            # 跑全部 Trader 测试
./vendor/bin/phpunit --filter IndicatorCalculator   # 只跑指标计算器
```

已覆盖：

1. ✅ Candle 校验 high/low 边界
2. ✅ ArrayDataProvider 时间戳严格升序 + K 线对齐
3. ✅ ExitRules 固定止损用 low ≤ stopPrice 判定 + stopPrice 成交
4. ✅ ExitRules ROI 目标解析（乱序输入也能按分钟匹配）
5. ✅ 入场使用 i+1 根 open + 滑点
6. ✅ 止损执行价 = stopPrice（夹逼到 [low, high]）
7. ✅ maker / taker / stop_loss 手续费正确分档
8. ✅ Wallet 超支抛异常；snapshot 正确折算总资产
9. ✅ ProtectionManager 冷却锁按 exit_reason 精确生效
10. ✅ PerformanceReport 精确吻合
11. ✅ E2E：EmaCrossStrategy 至少产生 1 笔完整交易
12. ✅ 策略注册表规范化 + 错误提示
13. ✅ BollingerRsiMeanReversionStrategy 指标数学正确性
14. ✅ IndicatorCalculator 33 方法：长度契约 / 值域 / 解析解 / 坑点验证 / 鲁棒性
15. ✅ KlinesCsvReader 读写回环 / 去重排序 / 异常处理
16. ✅ loadDataProvider 文件存在加载 / 缺失提示 / 自动下载分支
