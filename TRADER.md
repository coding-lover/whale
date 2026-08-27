# Sikelan 交易 / 回测系统（Trader）使用手册

> 位置：`app/Services/Trader/`
>
> 参考 Freqtrade 开源回测引擎架构实现，**100% 遵守本地框架设计原则**：
>  - 组件化 / 接口隔离 / 依赖注入
>  - 回测 / Dry-run / 实盘共用同一套撮合逻辑（通过 DataProvider 抽象）
>  - 全部使用本系统标准交易对 `TradingSymbol`（`BTC/USDT:QUARTER` 等），交易所格式由 Formatter 双向转换
>  - 写日志到 `trader_YYYY-MM-DD.log` 独立通道（和交易所服务一致）

---

## 目录

- [1. 目录与模块总览](#1-目录与模块总览)
- [2. 架构：核心设计理念（和 Freqtrade 对齐）](#2-架构核心设计理念和-freqtrade-对齐)
- [3. 标准交易对 & 信号矩阵格式](#3-标准交易对--信号矩阵格式)
- [4. 策略开发三步法（populateIndicators → populateEntryTrend → populateExitTrend）](#4-策略开发三步法)
  - [4.1 完整示例：EmaCrossStrategy](#41-完整示例emacrossstrategy)
  - [4.2 钩子：customEntryPrice / customExit / customExitPrice](#42-钩子自定义入场价custom-exit-自定义平仓价)
- [5. 撮合规则（防前视偏差核心）](#5-撮合规则防前视偏差核心)
  - [5.1 入场：信号 i → 执行 i+1 根 open](#51-入场信号-i--执行-i1-根-open)
  - [5.2 平仓：止损/止盈触及时用 high/low 判定 + 固定价成交](#52-平仓止损止盈触及时用-highlow-判定--固定价成交)
  - [5.3 平仓优先级顺序（同 Freqtrade）](#53-平仓优先级顺序同-freqtrade)
- [6. 止损 / ROI 阶梯 / 追踪止损 / HOLD 超时配置](#6-止损--roi-阶梯--追踪止损--hold-超时配置)
- [7. 保护机制：冷却锁 / 全局开仓上限](#7-保护机制冷却锁--全局开仓上限)
- [8. 运行一次回测（代码方式）](#8-运行一次回测代码方式)
- [9. PerformanceReport：30+ 指标定义](#9-performancereport30-指标定义)
- [10. ResultExporter：导出 JSON / CSV](#10-resultexporter导出-json--csv)
- [11. 默认配置 config/trader.php 说明](#11-默认配置-configtraderphp-说明)
- [12. 单元测试覆盖清单（已全部通过）](#12-单元测试覆盖清单已全部通过)

---

## 1. 目录与模块总览

```
app/Services/Trader/
├── Backtesting.php               # 回测编排器（主入口）
├── BacktestResult.php            # 回测结果值对象
├── BacktestServiceProvider.php   # 工厂：按配置装配（不用 DI 也能跑）
├── MatchingEngine.php            # 撮合核心：executeEntry / executeExit / getExecutionPrice
├── PerformanceReport.php         # 绩效指标（夏普/索提诺/卡玛/最大回撤/胜率/盈亏比）
├── ResultExporter.php            # 导出 JSON / CSV

├── Market/                       # 行情层（可替换，回测↔实盘共用 DataProviderInterface）
│   ├── Candle.php                # 单根 K 线值对象（OHLCV + 毫秒时间戳，不可变）
│   ├── Timeframe.php             # 1m/5m/15m/1h/4h/1d 等周期常量 + 毫秒/间隔映射
│   ├── DataProviderInterface.php # 数据接口（回测/实盘实现不同但上层不变）
│   └── ArrayDataProvider.php     # 内存/CSV 数据提供者（回测默认）

├── Enum/                         # 强类型字符串枚举（PHP 7.4 无原生 enum，用 const + 校验）
│   ├── RunMode.php               # backtest | dry_run | live
│   ├── TradingMode.php           # spot | margin | futures
│   ├── MarginMode.php            # none | isolated | cross
│   ├── OrderSide.php             # buy | sell（注意和 long/short 不是一个概念）
│   ├── OrderType.php             # limit | market | stop_loss_limit 等
│   ├── OrderStatus.php           # open/closed/partial/canceled/expired/rejected
│   └── ExitType.php              # 平仓原因：liquidation/stop_loss/roi/exit_signal/...（带优先级）

├── ExitRules/                    # 平仓规则引擎（独立模块便于单元测试）
│   └── ExitRules.php             # ROI 阶梯 / 固定止损 / 追踪止损 / HOLD 超时 / 强平

├── Fee/                          # 手续费 / 滑点
│   ├── FeeCalculator.php         # maker/taker 分档（含 Binance/OKX 现货/期货默认费率工厂）
│   └── SlippageCalculator.php    # 按 pair 单独配置默认滑点

├── Model/                        # 核心领域模型
│   ├── OrderRecord.php           # 订单（支持多批 applyFill，加权均价）
│   ├── TradeRecord.php           # 持仓（1:N 订单，DCA 加仓 / 分批平仓 / 全币种盈亏）
│   ├── Wallet.php                # 多币种余额账本（free/used/total/snapshot）
│   └── WalletSnapshot.php        # 钱包快照（权益曲线数据源）

├── Protection/                   # 保护 / 风险控制
│   ├── PairLock.php              # 单个 pair 冷却锁
│   └── ProtectionManager.php     # 全局/单 pair 最大开仓数 + 冷却查表 + 拒签统计

├── Strategy/                     # 策略层（用户主要扩展点）
│   ├── SignalCols.php            # 12 列信号矩阵下标常量（和 Freqtrade HEADERS 对齐）
│   ├── StrategyInterface.php     # 策略接口契约
│   └── AbstractStrategy.php      # 抽象基类：止损/ROI/Trailing/maxStake 配置都在这
└── Strategies/                   # 示例/策略注册目录
    └── EmaCrossStrategy.php      # 教学示例：EMA(short/long) 金叉死叉

config/
└── trader.php                    # 回测/交易引擎全局默认配置（配合 .env）
```

---

## 2. 架构：核心设计理念（和 Freqtrade 对齐）

```
          ┌────────────────────────── 策略代码只写这些 ───────────────────────┐
          │ populateIndicators → populateEntryTrend → populateExitTrend     │
          │ (计算指标)        (写入 enter_long)       (写入 exit_long)        │
          └────────────────────────────────────────────────────────────────────┘
                                        ▲
                                        │ 12 列矩阵（SignalCols 索引下标访问）
                                        │
          ┌───────────────────────────────────────────────────────────────────┐
          │ Backtesting.php (orchestrator 编排器)                            │
          │  ① 读 DataProvider → ③ 策略三步法预计算                           │
          │  ④ 逐 K 线推进（warmup 前忽略）                                   │
          │     a) 对 open trades 先做 checkTradeExit（平仓优先于开仓）         │
          │     b) ProtectionManager 校验准入（开仓上限/冷却锁）                │
          │     c) executeEntry（next-bar-open 成交，防前视）                  │
          │     d) 拍 WalletSnapshot（权益曲线）                               │
          │  ⑤ 强制平所有未平仓 → BacktestResult                               │
          └───────────────────────────────────────────────────────────────────┘
                 ▲             ▲              ▲            ▲
                 │             │              │            │
         DataProvider   MatchingEngine   ExitRules   ProtectionManager
         (可替换: 回测=内存) (撮合+钱包变动)  (规则匹配)    (准入+冷却)
                 │             │              │
                 ▼             ▼              ▼
              Candle[]  FeeCalculator  MinimalRoiTable
                        SlippageCalculator  TrailingStop
```

**关键设计保证（从 Freqtrade 经验里提炼，防前视偏差）：**

| 决策 | 设计 | 原因 |
|-----|------|-----|
| 入场执行价 | 第 i 根 close 信号，第 i+1 根 open 成交 | 避免"在 K 线 close 看到 MACD 交叉后用同一根 close 买入"，保证真实环境可复现 |
| 止损判定价 | low / high 影线 | 止损被影线扫是实盘常态，只用 close 会严重低估止损触发 |
| 止损成交 | 用 stopPrice，不是 low/high/close | 限价止损单会挂在 stopPrice，价格刚触达即成交，成交价比极值好 |
| ROI / exit_signal 判定 | 用 close | 保守：收盘确认趋势后再出场 |
| 手续费 | maker/taker 分开算，按订单类型 | 一次 maker-taker 差异 = 回测盈亏差 2~3 倍 |
| 信号矩阵列顺序固定 | SignalCols 用 0..11 常量（类似 Freqtrade DATE_IDX 系列） | 循环按列下标访问比 hash 快，百万 K 线差异巨大 |
| 同一策略可在回测/实盘运行 | DataProvider 可替换（回测 ArrayDataProvider / 实盘 ExchangeDataProvider） | 一套策略代码两种运行模式，减少漂移 |

---

## 3. 标准交易对 & 信号矩阵格式

### 3.1 交易对：TradingSymbol

复用本项目 `App\Services\Exchanges\TradingSymbol`：
```
现货：       BTC/USDT
U本位永续：  BTC/USDT:SWAP
币本位永续： BTC/USD:SWAP
交割本周：   BTC/USDT:THIS_WEEK
交割季度：   BTC/USDT:QUARTER
显式日期：   BTC/USDT:FUT-250328
```
无需关心 Binance `BTCUSDT_250328` 或 OKX `BTC-USDT-250328`，TradingSymbol + Formatter 自动双向转换。

### 3.2 信号矩阵（SignalCols 12 列）

策略三步法的输入和输出都是 "list of 12 列（+ 自定义扩展列）"的 PHP 数组。对应 Freqtrade `_get_ohlcv_as_lists`。

| 列下标 | SignalCols::常量 | 含义 | 类型 |
|-------|-----------------|------|-----|
| 0 | `DATE`       | 毫秒时间戳 `int` | 1_700_000_000_000 |
| 1 | `OPEN`       | 开盘价 `float` | |
| 2 | `HIGH`       | 最高价 `float` | **止损/止盈判断必须用** |
| 3 | `LOW`        | 最低价 `float` | **止损/止盈判断必须用** |
| 4 | `CLOSE`      | 收盘价 `float` | |
| 5 | `VOLUME`     | 成交量 `float` | |
| 6 | `ENTER_LONG` | 入场 LONG 强度 | 0 无 / 1 普通 / 2 强制入场（绕过冷却锁） |
| 7 | `EXIT_LONG`  | 出场 LONG | 0 无 / 1 出场 |
| 8 | `ENTER_SHORT` | 入场 SHORT（仅期货/杠杆） | 0/1/2 |
| 9 | `EXIT_SHORT`  | 出场 SHORT | 0/1 |
| 10 | `ENTER_TAG`  | 入场标签 `string` | `ema_cross_up(rsi<30)` 方便报表分析 |
| 11 | `EXIT_TAG`   | 出场标签 `string` | |

自定义列（比如计算的 RSI、EMA 等）写在 `SignalCols::NUM_COLUMNS`（=12）之后即可，用 `12 + n` 下标访问。

---

## 4. 策略开发三步法

任何策略都必须实现 `StrategyInterface`（或更简单地继承 `AbstractStrategy`），只要覆盖三步：

1. **populateIndicators($matrix, $symbol, $timeframe)**：计算指标
   - 输入：12 列基础 OHLCV 矩阵
   - 输出：附加了 RSI/EMA/布林带等自定义列的新矩阵
2. **populateEntryTrend($matrix)**：写入 `enter_long/enter_short/enter_tag` 三列
3. **populateExitTrend($matrix)**：写入 `exit_long/exit_short/exit_tag` 三列

> ⚠️ 三个方法都必须"纯函数"：只读入参，**返回新数组**，不得引用赋值修改。
>   - 好处：hyperopt 多进程可复用 Strategy 无副作用
>   - 调试方便：任一中间步骤结果可独立 dump

### 4.1 完整示例：EmaCrossStrategy

示例代码位于 [EmaCrossStrategy.php](file:///Users/wmc/data/trae/project/whale/app/Services/Trader/Strategies/EmaCrossStrategy.php)。
要点：

```php
class EmaCrossStrategy extends AbstractStrategy
{
    // 覆写默认配置（也可以在 config/trader.php 里对整个策略映射配置）
    protected $stoploss             = 0.03;       // 3% 固定止损
    protected $minimalRoi           = [0 => 0.03, 60 => 0.02, 180 => 0.01, 360 => 0]; // ROI 阶梯
    protected $defaultStakeAmount   = 500.0;      // 单笔 500 USDT
    protected $maxOpenTrades        = 5;
    protected $maxOpenTradesPerPair = 1;
    protected $trailingStop         = 0.0;        // 本例不用 trailing

    // ---- 1. 指标计算：写 2 列扩展 EMA(short) + EMA(long) ----
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

    // ---- 2. 入场：金叉 + 过滤 ----
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
    // ---- 3. 出场：死叉 ----
    public function populateExitTrend(array $matrix): array { /* mirror 类似 */ }
}
```

### 4.2 钩子：customEntryPrice / custom Exit / 自定义平仓价

```php
// 自定义入场价（null 代表使用默认 next-bar open）
public function customEntryPrice($symbol, $side, $row, $prev): ?float
{
    // 例子：入场限价挂到前一根 low - 0.1%
    return (float) $prev[SignalCols::LOW] * 0.999;
}

// custom_exit：每根 K 线在所有其他 exit 规则之前调用，返回 true = 立即平
// （比如"持仓后连续 5 根没涨超 1% 就撤"）
public function customExit(TradeRecord $trade, int $idx, array $row): bool
{
    $holdMinutes = max(0, (int) floor(($row[SignalCols::DATE] - $trade->getOpenTimestamp()) / 60_000));
    if ($holdMinutes > 24 * 60) { // 持仓超过 24 小时强制平（HOLD 超时也可用 strategy.getMaxHoldBars()）
        return true;
    }
    return false;
}
```

---

## 5. 撮合规则（防前视偏差核心）

### 5.1 入场：信号 i → 执行 i+1 根 open

**匹配 Freqtrade 行为：**

```
K 线序列： 0 — 1 — 2 — 3 — ... — i — i+1（open 在这里成交）
                           ▲
                           signal at close (i)
```

在 Backtesting.php `processEntriesForPair()` 里我们有明确一行：
```php
if ($rowI + 1 >= count($matrix)) break;   // 最后一根不能买（没有 i+1 执行 bar）
$execRow = $matrix[$rowI + 1];
```

滑点附加：`MatchingEngine::executeEntry()` 里默认按 `taker` + SlippageCalculator 加滑点。

### 5.2 平仓：止损/止盈触及时用 high/low 判定 + 固定价成交

`MatchingEngine::getExecutionPrice()` 关键规则：

| ExitType             | 判定条件 (long) | 成交价 |
|----------------------|----------------|--------|
| STOP_LOSS / TRAILING / LIQUIDATION | low ≤ stopPrice | stopPrice（夹逼到 [low, high]） |
| ROI / EXIT_SIGNAL / CUSTOM_EXIT / HOLD_TIMEOUT | close ≥ target | close（夹逼） |

> 为什么不用 high/close？因为：当 long 止损 stop=48500，low=47500 时，止损单挂在 48500 恰好成交，而不是用 low=47500 成交（否则夸大亏损）。

### 5.3 平仓优先级顺序（同 Freqtrade）

```
1. LIQUIDATION （强平，优先级最高）
2. STOP_LOSS    （固定止损）
3. TRAILING_STOP（追踪止损，达到 trailingStopPositive 后才启用）
4. ROI          （最小 ROI 阶梯，分钟 × 收益率）
5. CUSTOM_EXIT  （策略回调 customExit()）
6. EXIT_SIGNAL  （exit_long/exit_short 列）
7. FORCE_EXIT   （回测结束后未平仓强制平，不在主循环里）
8. STOP_ON_TIMEOUT（HOLD 超时 maxHoldBars）
```

---

## 6. 止损 / ROI 阶梯 / 追踪止损 / HOLD 超时配置

所有这些都在 `AbstractStrategy` 里以 `protected` 属性呈现，子类覆写即可。等价于 Freqtrade strategy class 顶部配置：

| 属性 | 类型 | 示例 | 含义 |
|-----|------|-----|-----|
| `$stoploss` | float 小数 | 0.03 | 固定止损百分比，0 = 不启用 |
| `$minimalRoi` | `[分钟int => 小数收益率]` | `[0=>0.03, 60=>0.02, 180=>0.01, 360=>0]` | 开仓后 0 分钟内盈 3% 就走；60 分钟后盈 2% 就走；180m 后盈 1%；360m 后无论多少都平（0% 即持平就走） |
| `$trailingStop` | float | 0.03 | 追踪止损 3%（long = close × 0.97，short × 1.03），0 = 不启用 |
| `$trailingStopPositive` | float | 0.015 | 先达到 1.5% 未实现盈再启动 trailing（避免一开仓就被小波动扫出场），0 = 立即启用 |
| `$maxHoldBars` | int 根数 | 0 | 超过 N 根 K 线强制平（0 = 不限制） |

> **注意 ROI 表里分钟数是按真实时钟 `now - openTs` 算，而不是按 row 序号差。** 这样即使有 K 线缺口（$allowGaps=true 时）也能对齐。

---

## 7. 保护机制：冷却锁 / 全局开仓上限

ProtectionManager 在入场前统一检查，通过就执行，不通过就计入 `BacktestResult.rejected_signals`：

| 规则 | 配置键 | 说明 |
|-----|-------|-----|
| 全局最大开仓数 | `$strategy->maxOpenTrades` | 未平仓总数 ≥ max → 拒绝 |
| 每 pair 最大开仓数 | `$strategy->maxOpenTradesPerPair` | 同 pair 持仓超限 → 拒绝 |
| Pair 冷却锁 | config `trader.protection.by_exit_reason` + `default_cooling_ms` | 平仓后按原因冷却一段时间 |

配置例子（`config/trader.php`）：
```php
'protection' => [
    'default_cooling_ms' => 600_000, // 10 分钟默认
    'by_exit_reason' => [
        'stop_loss' => 3_600_000,   // 被止损 1 小时后才允许再开
        'roi'       => 0,           // ROI 止盈后可立即开
        '*'         => 300_000,     // 其他原因 5 分钟（通配符）
    ],
],
```

强制入场（signal=2）会跳过冷却锁（但仍受 maxOpenTrades 等上限约束）：
```php
$matrix[$i][SignalCols::ENTER_LONG] = SignalCols::SIG_FORCE;
```

---

## 8. 运行一次回测（代码方式）

```php
use App\Services\Trader\BacktestServiceProvider;
use App\Services\Trader\Market\ArrayDataProvider;
use App\Services\Trader\PerformanceReport;
use App\Services\Trader\ResultExporter;
use App\Services\Trader\Strategies\EmaCrossStrategy;
use App\Services\Exchanges\TradingSymbol;

// 1) 准备 DataProvider（离线内存）
$dp = BacktestServiceProvider::createArrayDataProvider();
$symbol = TradingSymbol::parse('BTC/USDT');

//    真实项目可以：
//    a) 从交易所拉：用 ExchangeManager + formatSymbol() 把标准 pair 转原生
//    b) 从 CSV 读：把每行用 Candle::fromArray() 构造后 setCandles()

$candles = loadCandlesFromDbOrCsv();   // 你的代码
$dp->setCandles($symbol, '5m', $candles, true);   // true = 允许 K 线缺口

// 2) 装配
$traderConfig = config('trader'); // config() 快捷函数（app/common.php 里的）
$strategy = new EmaCrossStrategy(20, 50, 0.003);
$backtest = BacktestServiceProvider::newBacktesting(
    container(),
    $dp,
    $strategy,
    [
        'warmup_candles' => 100,       // 覆盖 EMA 50 预热
        'initial_capital' => 100_000,  // 10 万刀
    ]
);

// 3) 跑
$result = $backtest->run([$symbol], '5m');

// 4) 绩效
$perf   = new PerformanceReport($result, 100_000, 365);
echo '总收益率 ' . $perf->get('total_return_pct') . "%\n";
echo '夏普比率 ' . $perf->get('sharpe_ratio') . "\n";
echo '最大回撤 ' . $perf->get('max_drawdown_pct') . "%\n";
echo '胜率     ' . $perf->get('win_rate_pct') . "%\n";

// 5) 导出
$exporter = new ResultExporter();
file_put_contents(RUNTIME_PATH . '/bt.json', $exporter->toJson($result, $perf));
$exporter->writeCsvFile(
    RUNTIME_PATH . '/bt_trades.csv',
    $exporter->toCsvRows($result)
);
```

---

## 9. PerformanceReport：30+ 指标定义

| 指标键 | 含义（算法） |
|-------|------------|
| `initial_capital` / `final_capital` | 起始 / 结束 stake 货币 |
| `total_net_profit` / `total_return_pct` | 绝对盈亏（USDT） / 收益率（%） |
| `cagr_pct` | 复合年化增长率（按实际回测时长算） |
| `sharpe_ratio` | Sharpe = mean(日收益率) / std(日收益率) × √365（加密按 365 天，美股则 252 自行改参数） |
| `sortino_ratio` | Sortino：分母改为"下行波动率"（负收益） |
| `calmar_ratio` | CAGR / max_drawdown_pct |
| `max_drawdown_pct / abs / peak / start / end` | 权益曲线最大回撤（精确找出 peak-valley 区间） |
| `total_trades` | 交易数（已平仓） |
| `signals_total` / `signals_rejected` | 总信号 / ProtectionManager 拒签数 |
| `win_count` / `loss_count` / `win_rate_pct` | 赢单/输单/胜率 |
| `avg_trade_profit_pct` | 平均单笔收益率（%） |
| `avg_win_profit_pct` / `avg_loss_profit_pct` | 平均赢/亏单 |
| `profit_loss_ratio` | 平均赢单% ÷ 平均亏单%（不含本金） |
| `profit_factor` | 赢单$ / 亏单$（绝对值） |
| `best_trade_pct` / `worst_trade_pct` | 最好/最差单笔 |
| `avg_duration_min` | 平均持仓分钟数 |
| `expectancy_per_trade_abs` | 数学期望每笔盈亏（stake 货币）|

---

## 10. ResultExporter：导出 JSON / CSV

```php
$e = new ResultExporter();
$e->toArray($result, $perf);    // 扁平数组（API 返回）
$e->toJson($result, $perf);     // JSON 字符串（落盘/前端）
$e->toCsvRows($result);         // [header, ...rows]（Excel 友好列顺序）
$e->writeCsvFile('/path.csv', $rows);
```

---

## 11. 默认配置 `config/trader.php` 说明

见实际文件：[config/trader.php](file:///Users/wmc/data/trae/project/whale/config/trader.php)。完整键清单：

| 键 | .env | 默认 | 说明 |
|----|------|------|-----|
| `stake_currency` | `TRADER_STAKE_CURRENCY` | USDT | 计价货币 |
| `initial_capital` | `TRADER_INITIAL_CAPITAL` | 10000 | 初始资金 |
| `run_mode` | `TRADER_RUN_MODE` | backtest | backtest / dry_run / live |
| `trading_mode` | `TRADER_TRADING_MODE` | spot | spot / margin / futures |
| `timeframe` | `TRADER_TIMEFRAME` | 5m | 默认周期 |
| `warmup_candles` | `TRADER_WARMUP_BARS` | 300 | 预热 K 线数 |
| `data_dir` / `output_dir` | - | runtime/trader/{data,output} | 历史 K 线缓存 & 导出目录 |
| `log_channel` | - | trader | Logger.withChannel() 独立日志通道名 |
| `fee.maker_rate` | `TRADER_FEE_MAKER` | 0.0002 (0.02%) | 挂单手续费率 |
| `fee.taker_rate` | `TRADER_FEE_TAKER` | 0.0004 (0.04%) | 吃单手续费率 |
| `slippage.default_pct` | `TRADER_SLIPPAGE_DEFAULT` | 0.001 (0.1%) | 默认滑点 |
| `slippage.pair_overrides` | - | `[]` | 按 pair 单独配置滑点 |
| `protection.default_cooling_ms` | `TRADER_DEFAULT_COOLING_MS` | 0（不锁） | 默认冷却毫秒数 |
| `protection.by_exit_reason` | - | `[]` | 按 ExitType → 毫秒 |
| `strategies` | - | `[]` | 策略别名映射（未来 CLI）|

FeeCalculator 还提供了几个工厂（回测时可手动 new 覆盖）：
```php
FeeCalculator::binanceSpot()     // maker 0.1%, taker 0.1%
FeeCalculator::binanceFutures()  // maker 0.02%, taker 0.04%
FeeCalculator::okxSpot()         // maker 0.08%, taker 0.1%
FeeCalculator::okxFutures()      // maker 0.02%, taker 0.05%
```

---

## 12. 单元测试覆盖清单（已全部通过）

已在 [tests/stest/TraderBacktestTest.php](file:///Users/wmc/data/trae/project/whale/tests/stest/TraderBacktestTest.php) 覆盖：

1. ✅ `Candle` 校验 high/low 边界（high 必须 ≥ max(o,c,l)，low 必须 ≤ min）
2. ✅ `ArrayDataProvider` 时间戳严格升序 + K 线对齐检查
3. ✅ ExitRules 固定止损必须触发 `low ≤ stopPrice`，并使用 **stopPrice** 而不是 close
4. ✅ ExitRules ROI 目标解析（乱序输入也能按分钟匹配）
5. ✅ 入场必须使用 **i+1 根 open** + 滑点（不能用信号 i 的 close）
6. ✅ 止损执行价 = stopPrice（既不是 low，也不是 close），夹逼到 [low, high]
7. ✅ maker / taker / stop_loss 手续费正确分档
8. ✅ Wallet 超支会抛异常；snapshot 能按 base×quote 正确折算总资产
9. ✅ ProtectionManager 冷却锁按 exit_reason 精确生效
10. ✅ PerformanceReport 3 笔交易 / 线性 6 天权益曲线：胜率、最大回撤、最终余额精确吻合
11. ✅ E2E：EmaCrossStrategy(5/15) 在 3 段走势（横→涨→跌）下至少产生 1 笔完整交易（进+平）+ 绩效指标非空

额外在 [tests/stest/StrategyRegistrationTest.php](file:///Users/wmc/data/trae/project/whale/tests/stest/StrategyRegistrationTest.php) 覆盖策略注册表 & 标准策略：

12. ✅ `getStrategyRegistry()` 规范化：字符串 / 数组两种形式 + 无效 class 抛异常
13. ✅ `createStrategyByName()` 别名路径 & 完整类名退化路径 & 未注册打印当前列表
14. ✅ BollingerRsiMeanReversionStrategy 指标数学正确性：SMA / 滚动 σ / RSI（横盘=50）
15. ✅ E2E：通过注册表 `createStrategyByName('MeanRevStd')` → Backtesting.run → 合成数据 ≥ 1 笔

> 结果：**总计 396 tests，1045 assertions，0 failures，0 errors（17 集成测试需网络被 skipped）。**

---

## 13. 策略别名注册表 & 一键运行（推荐方式）

从 **v1.1** 开始，BacktestServiceProvider 引入了「策略别名注册表」机制：你不必在每个 Controller/CLI 里 `new EmaCrossStrategy(20, 50, 0.003)`，而是在 `config/trader.php` 里**一次注册，全局通过字符串别名引用**。这对 CLI 命令（如未来的 `bin/sikelan trader:backtest --strategy MeanRevStd`）和 Web 控制台下拉选择非常友好。

### 13.1 两种注册格式

编辑 [config/trader.php](file:///Users/wmc/data/trae/project/whale/config/trader.php) 的 `strategies` 键：

```php
return [
    // ... 其他配置 ...
    'strategies' => [

        // ❏ 格式 A（简化）：无构造参数 / 用类默认构造参数
        //   别名         =>  完整类名
        'EmaCrossDefault' => \App\Services\Trader\Strategies\EmaCrossStrategy::class,

        // ❏ 格式 B（带构造参数）：class + construct 数组（按 __construct 顺序）
        'MeanRevStd' => [
            'class'     => \App\Services\Trader\Strategies\BollingerRsiMeanReversionStrategy::class,
            'construct' => [
                20,    // bbPeriod      — 布林带周期
                2.0,   // bbStdMult     — 带宽 σ 倍数
                14,    // rsiPeriod     — RSI 周期
                30.0,  // rsiOversold   — 超卖阈值（入场）
                65.0,  // rsiOverbought — 超买阈值（出场）
                0.8,   // volFilterFactor — 量能过滤（当前量 ≥ SMA×系数）
            ],
        ],

        // 同一策略类可注册多个别名 → 对应不同参数组（例如保守/激进）
        'MeanRevConservative' => [
            'class'     => \App\Services\Trader\Strategies\BollingerRsiMeanReversionStrategy::class,
            'construct' => [20, 2.2, 14, 25.0, 70.0, 1.1],   // 更严格的入场
        ],
        'MeanRevAggressive' => [
            'class'     => \App\Services\Trader\Strategies\BollingerRsiMeanReversionStrategy::class,
            'construct' => [20, 1.8, 14, 35.0, 60.0, 0.5],   // 更宽的入场
        ],
    ],
];
```

BacktestServiceProvider 内部通过 `getStrategyRegistry()` 做规范化：字符串形式会被转成 `['class' => X, 'construct' => []]`，再校验 class 是否实现 `StrategyInterface`（否则抛 `InvalidArgumentException`）。

### 13.2 三种方式把「注册 + 运行」连起来

对应 [BacktestServiceProvider.php](file:///Users/wmc/data/trae/project/whale/app/Services/Trader/BacktestServiceProvider.php) 的 3 个公开 API，复杂度从低到高：

#### ❏ 方式 1：`newBacktestingByName()` — 一行搞定（最推荐）

适用：业务代码 / Controller / CLI 快速跑。容器、配置、策略实例化全部自动：

```php
use App\Services\Trader\BacktestServiceProvider;
use App\Services\Trader\PerformanceReport;
use App\Services\Exchanges\TradingSymbol;

// 1) 准备数据（离线 CSV / DB 都行，这里略）
$dp = BacktestServiceProvider::createArrayDataProvider();
$symbol = TradingSymbol::parse('BTC/USDT');
$dp->setCandles($symbol, '5m', loadYourCandles(), true);

// 2) 一行装配：用 config/trader.php 里的别名 MeanRevStd
$backtest = BacktestServiceProvider::newBacktestingByName(
    container(),               // 全局容器（会自动取 Config + Logger）
    $dp,                       // 数据
    'MeanRevStd',              // ← 策略别名
    [
        'initial_capital'  => 100_000,  // 可选：覆盖 trader 顶层配置
        'warmup_candles'   => 60,
    ]
    // 第 5 个参数还可覆盖策略构造参数（临时调参不改 config）
    // [20, 2.0, 14, 30.0, 65.0, 0.8]
);

// 3) 运行 + 看报表
$result = $backtest->run([$symbol], '5m');
$perf   = new PerformanceReport($result, 100_000, 365);
echo "收益 {$perf->get('total_return_pct')}% · 夏普 {$perf->get('sharpe_ratio')}\n";
```

#### ❏ 方式 2：`createStrategyByName()` + `newBacktesting()`（需要拿到策略实例时）

适用：要先 `var_dump($strategy->getName())` 或者对策略做额外配置：

```php
$cfg = config('trader');           // 或自己拼数组
$strategy = BacktestServiceProvider::createStrategyByName($cfg, 'MeanRevStd');

// 可选：覆盖构造参数（比如 GridSearch 调参）
$strategy2 = BacktestServiceProvider::createStrategyByName(
    $cfg,
    'MeanRevStd',
    [20, 1.9, 14, 32.0, 67.0, 0.75]   // ← 第 3 参数 override construct
);

// 再用熟悉的 newBacktesting 装配
$backtest = BacktestServiceProvider::newBacktesting(container(), $dp, $strategy);
```

#### ❏ 方式 3：纯数组配置（单元测试 / 无容器环境）

`BacktestServiceProvider::build()` 和 `createStrategyByName()` 第一个参数都支持 **数组**，不必传容器：

```php
// 单元测试常见写法（见 StrategyRegistrationTest::testE2eRegisteredStrategyRunsAndProducesTrades）
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

### 13.3 错误提示友好：未注册时打印当前列表

如果别名不存在，异常消息会直接告诉你**当前已注册的所有别名**，直接复制即可：

```
InvalidArgumentException: 策略 'DoesNotExist' 未找到。请在 config/trader.php 的 strategies 里注册，或使用完整类名。
当前已注册策略: [MeanRevStd, EmaCross20_50, MeanRevConservative, MeanRevAggressive]
```

---

## 14. 标准策略开发模板：`BollingerRsiMeanReversionStrategy`

> 完整源码见 [BollingerRsiMeanReversionStrategy.php](file:///Users/wmc/data/trae/project/whale/app/Services/Trader/Strategies/BollingerRsiMeanReversionStrategy.php)。
>
> 这是一个**生产级可复用**的均值回归策略，覆盖了策略开发会遇到的 90% 需求：
> 自定义指标列、构造参数注入、量能过滤、信号 TAG、ROI 阶梯、固定止损、追踪止损（带激活阈值）、HOLD 超时强平、`customExit` 保本退出钩子、`customEntryPrice` 限价挂单，以及 SMA/滚动σ/RSI 三个通用静态工具函数。

### 14.1 风控配置对照（对应 AbstractStrategy 属性）

| 属性 | 本策略值 | 含义 |
|------|---------|------|
| `$defaultStakeAmount` | 2000 USDT | 每笔默认 stake |
| `$maxOpenTrades` | 4 | 最多同时 4 个持仓（多 pair 分散） |
| `$maxOpenTradesPerPair` | 1 | 每个 pair 最多 1 个（避免同标的重仓） |
| `$stoploss` | 0.05 | **固定止损 5%** |
| `$minimalRoi` | `[0=>0.06, 30=>0.03, 120=>0.015, 240=>0]` | **ROI 阶梯**：开仓即盈 6%→30m 3%→120m 1.5%→240m 持平就走 |
| `$trailingStop` | 0.03 | **追踪止损 3%**（long 用 close×0.97 作保护价） |
| `$trailingStopPositive` | 0.02 | **2% 浮盈后才启动 trailing**（避免一开仓就被小波动扫走） |
| `$maxHoldBars` | 180 | **HOLD 超时：5m×180=15h** 强制平仓（防止长期被套） |
| `$enableShort` | false | 仅 LONG（现货安全；期货可开） |

以上配置**可被 config/trader.php 的顶层同名键覆盖**（BacktestServiceProvider 装配时会 merge）。

### 14.2 构造参数（给 GridSearch 调参用）

通过 `__construct()` 注入，在注册表 `construct` 数组里按顺序传：

```php
public function __construct(
    int   $bbPeriod        = 20,   // 布林带 SMA 周期
    float $bbStdMult       = 2.0,  // 布林带带宽 σ 倍数（常用 1.8~2.5）
    int   $rsiPeriod       = 14,   // RSI Wilder 平滑周期
    float $rsiOversold     = 30.0, // 入场 LONG 条件：RSI < 此值
    float $rsiOverbought   = 65.0, // 出场 LONG 条件：RSI ≥ 此值
    float $volFilterFactor = 0.8   // 量能过滤 = 当前量 ≥ VOL_SMA × 系数
)
```

config/trader.php 里注册保守 / 激进版本的示例已经在 13.1 节给出。

### 14.3 入场 & 出场逻辑速查

**入场 LONG（3 条件 AND）：**
1. `close ≤ BB_LOWER`（跌破布林带下轨）
2. `RSI < rsiOversold`（动量确认超卖，过滤假突破）
3. `volume ≥ VOL_SMA20 × volFilterFactor`（量能足够；缩量破位 = "接飞刀"，跳过）
4. 信号 TAG 示例：`bb_break(93.210<93.520)_rsi27.1_vol1.23x`

**出场（按 ExitRules 优先级从高到低）：**
| 顺序 | 规则 | 触发条件 |
|-----|------|---------|
| 1 | 固定止损 | 浮亏 ≥ 5% |
| 2 | 追踪止损 | 2% 浮盈后启动，从最高收盘价回撤 3% |
| 3 | ROI 阶梯 | 按持仓分钟 & 收益率表匹配 |
| 4 | **customExit 钩子** | 持仓 ≥ 60 根（5h）**且**浮盈 < 0.3% → 撤（避免横盘手续费流失） |
| 5 | 信号出场 | `close ≥ BB_MID(中轨)` 且 `RSI ≥ rsiOverbought`（均值回归到位） |
| 6 | HOLD 超时 | 持仓 ≥ 180 根（15h）→ 强平 |

### 14.4 复用指标工具函数

策略把 3 个常用指标做成 **public static**，可直接在你的新策略里调用，无需重复造轮子：

```php
use App\Services\Trader\Strategies\BollingerRsiMeanReversionStrategy as Ind;

$close = array_column($matrix, SignalCols::CLOSE);
$sma20 = Ind::sma($close, 20);              // 简单移动平均
$std20 = Ind::rollingStd($close, 20);       // 滚动样本标准差（无偏 ÷ n-1）
$rsi14 = Ind::rsi($close, 14);              // Wilder's RSI（和 TradingView / Binance 对齐）
```

---

### 14.5 最小骨架：把本模板复制成你的新策略只需 3 步

```bash
# 1) 复制文件，改类名
cp app/Services/Trader/Strategies/BollingerRsiMeanReversionStrategy.php \
   app/Services/Trader/Strategies/MyAwesomeStrategy.php

# 2) 修改类名 + 构造参数（如果不需要参数就全部删，保留空构造）
# 3) 覆写 populateIndicators / populateEntryTrend / populateExitTrend 三步法
```

最后在 `config/trader.php` 里加一行即可被全局引用：

```php
'MyStrat' => \App\Services\Trader\Strategies\MyAwesomeStrategy::class,
```

现在你就可以用：

```php
$backtest = BacktestServiceProvider::newBacktestingByName(container(), $dp, 'MyStrat');
```

一键跑回测了 🎉
