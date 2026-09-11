<?php

return [
    // ------------------------------------------------------------------
    //  回测/交易引擎默认配置
    //  每个策略可以覆盖；实盘模式（LIVE）下应优先用 strategy 的方法返回值
    // ------------------------------------------------------------------

    'stake_currency'      => env('TRADER_STAKE_CURRENCY', 'USDT'),
    'initial_capital'     => (float) env('TRADER_INITIAL_CAPITAL', 10000),

    'run_mode'            => env('TRADER_RUN_MODE', 'backtest'), // backtest | dry_run | live
    'trading_mode'        => env('TRADER_TRADING_MODE', 'spot'), // spot | margin | futures

    'timeframe'           => env('TRADER_TIMEFRAME', '5m'),
    'warmup_candles'      => (int) env('TRADER_WARMUP_BARS', 300),

    // 默认数据保存目录（CSV 导出、历史 K 线缓存）
    'data_dir'            => env('TRADER_DATA_DIR', RUNTIME_PATH . '/trader/data'),
    'output_dir'          => env('TRADER_OUTPUT_DIR', RUNTIME_PATH . '/trader/output'),

    // 日志通道（写独立日志 trader.log，已用 Logger.withChannel）
    'log_channel'         => 'trader',

    // ------------------------------------------------------------------
    //  手续费（默认 Binance 现货 VIP0 标准）
    // ------------------------------------------------------------------
    'fee' => [
        'maker_rate' => (float) env('TRADER_FEE_MAKER', 0.001),
        'taker_rate' => (float) env('TRADER_FEE_TAKER', 0.001),
    ],

    // ------------------------------------------------------------------
    //  滑点（默认 0.1%），可按 pair 单独配
    //  example: 'pair_overrides' => ['BTC/USDT' => 0.0005, 'ETH/USDT' => 0.0008]
    // ------------------------------------------------------------------
    'slippage' => [
        'default_pct'    => (float) env('TRADER_SLIPPAGE_DEFAULT', 0.001),
        'pair_overrides' => [],
    ],

    // ------------------------------------------------------------------
    //  保护管理器（冷却锁）：平仓后在 N 毫秒内不允许再开同一 pair
    //  key = ExitType 枚举常量字符串或 '*' 表示默认
    //  val = 毫秒数。0 = 不锁。
    // ------------------------------------------------------------------
    'protection' => [
        'default_cooling_ms' => (int) env('TRADER_DEFAULT_COOLING_MS', 0),
        'by_exit_reason'     => [
            // '*' 表示默认（没显式配置的 exit type）
            // '*'          => 0,
            // 'stop_loss'  => 3_600_000,   // 被止损 → 冷却 1 小时
        ],
    ],

    // ------------------------------------------------------------------
    //  策略注册表（别名 → 策略类 映射）
    //
    //  BacktestServiceProvider 支持两种注册写法：
    //
    //  ❏ 写法 A（简化，使用默认构造参数）：
    //       '别名' => 策略完整类名::class
    //
    //  ❏ 写法 B（带构造参数）：
    //       '别名' => [
    //           'class'     => 策略完整类名::class,
    //           'construct' => [参数1, 参数2, ...参数N],   // 按顺序传入 __construct
    //       ]
    //
    //  然后在业务代码中通过别名即可引用：
    //       $strategy = BacktestServiceProvider::createStrategyByName(container(), 'MeanRevStd');
    //       // 或者：
    //       $backtest = BacktestServiceProvider::newBacktestingByName(container(), $dp, 'MeanRevStd');
    // ------------------------------------------------------------------
    'strategies' => [
        // =================================================================
        //  标准模板 1：EMA 金叉死叉示例（含构造参数）
        //  EmaCrossStrategy::__construct(int $emaShort = 20, int $emaLong = 50, float $filterPct = 0.003)
        // =================================================================
        'EmaCross20_50' => [
            'class'     => \App\Services\Trader\Strategies\EmaCrossStrategy::class,
            'construct' => [20, 50, 0.003],
        ],

        // =================================================================
        //  标准模板 2：布林带(20, 2σ) + RSI(14, <30/>65) 均值回归（完整风控示例）
        //  BollingerRsiMeanReversionStrategy::__construct(
        //    $bbPeriod=20, $bbStdMult=2.0, $rsiPeriod=14,
        //    $rsiOversold=30.0, $rsiOverbought=65.0, $volFilterFactor=0.8
        //  )
        //  本策略内置风控：
        //    - 固定止损 5%，
        //    - ROI 阶梯：6%/30分钟后3%/120分钟后1.5%/240分钟后0%
        //    - Trailing stop 3%，2% 浮盈后才启动
        //    - maxHoldBars = 180 根 (15h) 强平
        //    - customExit：持仓 ≥ 60 根且浮盈 < 0.3% 就撤（避免不动手续费流失）
        // =================================================================
        'MeanRevStd' => [
            'class'     => \App\Services\Trader\Strategies\BollingerRsiMeanReversionStrategy::class,
            'construct' => [
                20,    // bbPeriod
                2.0,   // bbStdMult
                14,    // rsiPeriod
                30.0,  // rsiOversold
                65.0,  // rsiOverbought
                0.8,   // volFilterFactor（当前量 > 20 根 SMA × 0.8 才允许入场）
            ],
        ],

        // ---- 可以在这里扩展不同参数版本（例如保守/激进）----
        // 'MeanRevConservative' => [
        //     'class'     => \App\Services\Trader\Strategies\BollingerRsiMeanReversionStrategy::class,
        //     'construct' => [20, 2.2, 14, 25.0, 70.0, 1.1],   // 更严格的入场条件 + 更高量能
        // ],
        // 'MeanRevAggressive' => [
        //     'class'     => \App\Services\Trader\Strategies\BollingerRsiMeanReversionStrategy::class,
        //     'construct' => [20, 1.8, 14, 35.0, 60.0, 0.5],   // 更宽的入场：更宽带宽 1.8σ RSI 35
        // ],

        'WmcStrategy' => [
            'class'     => \App\Services\Trader\Strategies\WmcStrategy::class,
            // 参数经 SKR/USDT:SWAP 15m 30天数据网格寻优（12/60/0.005）；
            // 风控（止损1.5% + 宽ROI阶梯）在策略类 protected 属性中
            'construct' => [12, 60, 0.005],
        ],
    ],
];
