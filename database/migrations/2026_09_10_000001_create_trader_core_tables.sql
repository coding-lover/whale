-- ============================================================================
-- Sikelan quant_trade 首批核心表
-- 日期: 2026-09-10
-- MySQL 8.0+ / InnoDB / utf8mb4 / 应用层统一 UTC
--
-- 设计约定:
--   1. 价格/金额一律 DECIMAL（不用 FLOAT/DOUBLE，避免金融浮点误差）
--   2. 业务时间戳一律 BIGINT 毫秒（与 Candle/OrderRecord/TradeRecord 的
--      toArray() 字段一一对应，入库零转换）；表审计时间用 DATETIME(3)
--   3. 汇总指标做独立列（列表页可筛选/排序），大字段（权益曲线/完整指标/
--      多交易对/策略构造参数）放 JSON
--   4. 🔴 安全红线：API Key/Secret 绝不入库，exchange_accounts 只存
--      .env 凭证前缀（credential_ref，如 BINANCE），运行时走 env()
--   5. 可重复执行（CREATE TABLE IF NOT EXISTS）
--
-- 表清单（按依赖顺序）:
--   1. strategies        策略注册表（config/trader.php 别名的数据库镜像）
--   2. exchange_accounts  交易所账户（只存非敏感配置，密钥在 .env）
--   3. klines            K 线行情（回测/模拟/实盘共用的数据源）
--   4. backtests         回测任务结果主表（输入参数 + 30+ 项绩效指标）
--   5. backtest_trades   回测持仓成交明细（对应 TradeRecord::toArray()）
--   6. backtest_orders   回测订单明细（对应 OrderRecord，支持 DCA 审计）
-- ============================================================================

-- 1. 策略注册表 ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `strategies` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    `alias`             VARCHAR(64)     NOT NULL COMMENT '策略别名（config/trader.php 注册名，如 MeanRevStd）',
    `name`              VARCHAR(128)    NOT NULL COMMENT '策略可读名称（StrategyInterface::getName）',
    `class_name`        VARCHAR(255)    NOT NULL COMMENT '策略完整类名',
    `version`           VARCHAR(32)     NOT NULL DEFAULT '1.0.0' COMMENT '策略版本',
    `params`            JSON            NULL COMMENT '构造参数（config 里 construct 数组，如 [20,2.0,14]）',
    `default_timeframe` VARCHAR(8)      NOT NULL DEFAULT '1h' COMMENT '默认 K 线周期（1m/5m/15m/1h/4h/1d 等）',
    `trading_mode`      VARCHAR(10)     NOT NULL DEFAULT 'spot' COMMENT '交易模式 spot/margin/futures',
    `can_short`         TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否允许做空',
    `description`       VARCHAR(500)    NOT NULL DEFAULT '' COMMENT '策略说明',
    `status`            VARCHAR(16)     NOT NULL DEFAULT 'enabled' COMMENT '状态 enabled/disabled',
    `created_at`        DATETIME(3)     NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间（UTC）',
    `updated_at`        DATETIME(3)     NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间（UTC）',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_strategies_alias` (`alias`),
    KEY `idx_strategies_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='策略注册表';

-- 2. 交易所账户表 --------------------------------------------------------------
-- 注意：不存 api_key / secret / passphrase 明文，仅存 env 前缀引用
CREATE TABLE IF NOT EXISTS `exchange_accounts` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    `name`           VARCHAR(64)     NOT NULL COMMENT '账户备注名（如 binance-main）',
    `exchange`       VARCHAR(20)     NOT NULL COMMENT '交易所标识 binance/okx',
    `account_type`   VARCHAR(16)     NOT NULL DEFAULT 'spot' COMMENT '账户类型 spot/margin/futures',
    `credential_ref` VARCHAR(64)     NOT NULL COMMENT '.env 凭证前缀（如 BINANCE → BINANCE_API_KEY/BINANCE_SECRET）',
    `testnet`        TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否测试网',
    `ssl_verify`     TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '是否校验 SSL 证书',
    `proxy_enabled`  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否启用代理',
    `proxy_host`     VARCHAR(128)    NULL COMMENT '代理地址',
    `proxy_port`     INT UNSIGNED    NULL COMMENT '代理端口',
    `is_default`     TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否默认账户',
    `status`         VARCHAR(16)     NOT NULL DEFAULT 'enabled' COMMENT '状态 enabled/disabled',
    `remark`         VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '备注',
    `created_at`     DATETIME(3)     NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间（UTC）',
    `updated_at`     DATETIME(3)     NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间（UTC）',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_exchange_accounts_name` (`name`),
    KEY `idx_exchange_accounts_exchange` (`exchange`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='交易所账户（密钥不落库，走 .env credential_ref）';

-- 3. K 线行情表 ----------------------------------------------------------------
-- 对应 App\Services\Trader\Market\Candle（timestamp/open/high/low/close/volume）
-- 替代 runtime/trader/data 下的 CSV，支持范围查询与多交易所聚合
CREATE TABLE IF NOT EXISTS `klines` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    `exchange`     VARCHAR(20)     NOT NULL COMMENT '交易所 binance/okx',
    `symbol`       VARCHAR(32)     NOT NULL COMMENT '标准交易对（如 BTC/USDT、ETH/USDT:SWAP）',
    `timeframe`    VARCHAR(8)      NOT NULL COMMENT '周期 1m/5m/15m/30m/1h/4h/1d/1w',
    `open_time_ms` BIGINT          NOT NULL COMMENT 'K 线开盘时间（毫秒，Candle::timestamp）',
    `open`         DECIMAL(24,8)   NOT NULL COMMENT '开盘价',
    `high`         DECIMAL(24,8)   NOT NULL COMMENT '最高价',
    `low`          DECIMAL(24,8)   NOT NULL COMMENT '最低价',
    `close`        DECIMAL(24,8)   NOT NULL COMMENT '收盘价',
    `volume`       DECIMAL(24,8)   NOT NULL DEFAULT 0.00000000 COMMENT '成交量（基础货币）',
    `created_at`   DATETIME(3)     NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间（UTC）',
    `updated_at`   DATETIME(3)     NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间（UTC）',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_klines_ex_sym_tf_time` (`exchange`, `symbol`, `timeframe`, `open_time_ms`),
    KEY `idx_klines_sym_tf_time` (`symbol`, `timeframe`, `open_time_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='K 线行情（OHLCV）';

-- 4. 回测结果主表 --------------------------------------------------------------
-- 列分四组：关联/输入快照 → 资金与成本 → 绩效汇总（PerformanceReport::all）
--           → 运行状态/大 JSON
CREATE TABLE IF NOT EXISTS `backtests` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    -- 关联与策略快照（策略可能随后被修改，回测记录冗余保存当次名称/版本）
    `strategy_id`       BIGINT UNSIGNED NULL COMMENT '关联 strategies.id（策略后删不影响回测历史）',
    `strategy_alias`    VARCHAR(64)     NOT NULL COMMENT '策略别名快照',
    `strategy_name`     VARCHAR(128)    NOT NULL DEFAULT '' COMMENT '策略名称快照',
    `strategy_version`  VARCHAR(32)     NOT NULL DEFAULT '' COMMENT '策略版本快照',
    -- 回测输入参数
    `exchange`          VARCHAR(20)     NOT NULL DEFAULT 'binance' COMMENT '交易所',
    `symbols`           JSON            NOT NULL COMMENT '交易对列表（如 ["BTC/USDT","ETH/USDT"]）',
    `timeframe`         VARCHAR(8)      NOT NULL DEFAULT '1h' COMMENT 'K 线周期',
    `run_mode`          VARCHAR(12)     NOT NULL DEFAULT 'backtest' COMMENT '运行模式 backtest/dry_run/live',
    `trading_mode`      VARCHAR(10)     NOT NULL DEFAULT 'spot' COMMENT '交易模式 spot/margin/futures',
    `stake_currency`    VARCHAR(16)     NOT NULL DEFAULT 'USDT' COMMENT '计价货币',
    `start_ms`          BIGINT          NULL COMMENT '回测数据起点（毫秒）',
    `end_ms`            BIGINT          NULL COMMENT '回测数据终点（毫秒）',
    `initial_capital`   DECIMAL(20,8)   NOT NULL DEFAULT 10000.00000000 COMMENT '初始资金',
    `final_capital`     DECIMAL(20,8)   NULL COMMENT '期末资金',
    `warmup_candles`    INT             NOT NULL DEFAULT 0 COMMENT '预热 K 线数',
    `fee_maker`         DECIMAL(10,8)   NOT NULL DEFAULT 0.00100000 COMMENT 'maker 手续费率',
    `fee_taker`         DECIMAL(10,8)   NOT NULL DEFAULT 0.00100000 COMMENT 'taker 手续费率',
    `slippage_pct`      DECIMAL(10,8)   NOT NULL DEFAULT 0.00000000 COMMENT '滑点比例（小数）',
    `params`            JSON            NULL COMMENT '其他回测参数（可扩展输入）',
    -- 绩效汇总指标（列表页直接展示/排序，无需解析 JSON）
    `total_net_profit`  DECIMAL(20,8)   NULL COMMENT '净收益总额（stake 货币）',
    `total_return_pct`  DECIMAL(12,4)   NULL COMMENT '总收益率 %',
    `cagr_pct`          DECIMAL(12,4)   NULL COMMENT '年化收益率 %',
    `sharpe_ratio`      DECIMAL(10,4)   NULL COMMENT '夏普比率（365 天口径）',
    `sortino_ratio`     DECIMAL(10,4)   NULL COMMENT '索提诺比率',
    `calmar_ratio`      DECIMAL(10,4)   NULL COMMENT '卡玛比率',
    `max_drawdown_pct`  DECIMAL(12,4)   NULL COMMENT '最大回撤 %',
    `max_drawdown_abs`  DECIMAL(20,8)   NULL COMMENT '最大回撤绝对额',
    `mdd_peak_ms`       BIGINT          NULL COMMENT '最大回撤净值高点时间（毫秒）',
    `mdd_start_ms`      BIGINT          NULL COMMENT '最大回撤起点时间（毫秒）',
    `mdd_end_ms`        BIGINT          NULL COMMENT '最大回撤谷底时间（毫秒）',
    `total_trades`      INT             NOT NULL DEFAULT 0 COMMENT '已平仓交易数',
    `signals_total`     INT             NOT NULL DEFAULT 0 COMMENT '信号总数（含被拒）',
    `signals_rejected`  INT             NOT NULL DEFAULT 0 COMMENT '被保护机制拒绝的信号数',
    `win_count`         INT             NOT NULL DEFAULT 0 COMMENT '盈利交易数',
    `loss_count`        INT             NOT NULL DEFAULT 0 COMMENT '亏损交易数',
    `win_rate_pct`      DECIMAL(8,4)    NULL COMMENT '胜率 %',
    `avg_trade_pct`     DECIMAL(12,4)   NULL COMMENT '单笔平均收益 %',
    `avg_win_pct`       DECIMAL(12,4)   NULL COMMENT '平均盈利 %',
    `avg_loss_pct`      DECIMAL(12,4)   NULL COMMENT '平均亏损 %（绝对值口径）',
    `profit_factor`     DECIMAL(12,4)   NULL COMMENT '盈亏比（总盈利/总亏损绝对值）',
    `profit_loss_ratio` DECIMAL(12,4)   NULL COMMENT '平均盈亏比',
    `best_trade_pct`    DECIMAL(12,4)   NULL COMMENT '最佳单笔收益 %',
    `worst_trade_pct`   DECIMAL(12,4)   NULL COMMENT '最差单笔收益 %',
    `avg_duration_min`  DECIMAL(12,2)   NULL COMMENT '平均持仓时长（分钟）',
    `expectancy_abs`    DECIMAL(20,8)   NULL COMMENT '单笔期望收益（stake 货币）',
    -- 大字段与运行状态
    `equity_curve`      JSON            NULL COMMENT '权益曲线（WalletSnapshot::toArray 列表）',
    `metrics`           JSON            NULL COMMENT 'PerformanceReport::all() 完整指标快照（前向兼容）',
    `status`            VARCHAR(16)     NOT NULL DEFAULT 'completed' COMMENT '状态 running/completed/failed',
    `error_message`     TEXT            NULL COMMENT '失败原因（status=failed 时）',
    `duration_ms`       BIGINT          NULL COMMENT '回测执行耗时（毫秒）',
    `created_at`        DATETIME(3)     NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间（UTC）',
    `updated_at`        DATETIME(3)     NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间（UTC）',
    `deleted_at`        DATETIME(3)     NULL COMMENT '软删除时间（NULL=未删除）',
    PRIMARY KEY (`id`),
    KEY `idx_backtests_strategy` (`strategy_id`),
    KEY `idx_backtests_ex_tf` (`exchange`, `timeframe`),
    KEY `idx_backtests_status_created` (`status`, `created_at`),
    KEY `idx_backtests_created` (`created_at`),
    KEY `idx_backtests_deleted` (`deleted_at`),
    CONSTRAINT `fk_backtests_strategy` FOREIGN KEY (`strategy_id`)
        REFERENCES `strategies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='回测任务结果主表';

-- 5. 回测持仓成交明细表 --------------------------------------------------------
-- 字段与 TradeRecord::toArray() 完全对齐（snake_case 同名）
CREATE TABLE IF NOT EXISTS `backtest_trades` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    `backtest_id`         BIGINT UNSIGNED NOT NULL COMMENT '关联 backtests.id',
    `trade_id`            INT             NOT NULL COMMENT '回测内持仓自增 ID（trade_id）',
    `pair`                VARCHAR(32)     NOT NULL COMMENT '交易对（如 BTC/USDT:SWAP）',
    `direction`           ENUM('long', 'short') NOT NULL COMMENT '持仓方向',
    `trading_mode`        VARCHAR(10)     NOT NULL DEFAULT 'spot' COMMENT '交易模式',
    `leverage`            DECIMAL(12,4)   NOT NULL DEFAULT 1.0000 COMMENT '杠杆倍数（现货=1）',
    `stake_amount`        DECIMAL(20,8)   NOT NULL COMMENT '初始 stake（收益率分母，不随加仓变）',
    `amount`              DECIMAL(24,8)   NOT NULL DEFAULT 0.00000000 COMMENT '持仓 base 数量',
    `open_rate`           DECIMAL(24,8)   NOT NULL DEFAULT 0.00000000 COMMENT '加权平均入场价',
    `close_rate`          DECIMAL(24,8)   NOT NULL DEFAULT 0.00000000 COMMENT '加权平均平仓价',
    `open_timestamp_ms`   BIGINT          NOT NULL DEFAULT 0 COMMENT '首笔入场时间（毫秒）',
    `close_timestamp_ms`  BIGINT          NULL COMMENT '末笔平仓时间（毫秒，未平仓为 NULL）',
    `is_open`             TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '是否仍持仓',
    `close_reason`        VARCHAR(32)     NOT NULL DEFAULT 'none' COMMENT '平仓原因 ExitType（stop_loss/roi/exit_signal 等）',
    `enter_tag`           VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '入场标签',
    `exit_tag`            VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '出场标签',
    `fee_open`            DECIMAL(20,8)   NOT NULL DEFAULT 0.00000000 COMMENT '入场手续费合计（stake 货币）',
    `fee_close`           DECIMAL(20,8)   NOT NULL DEFAULT 0.00000000 COMMENT '平仓手续费合计',
    `funding_interest`    DECIMAL(20,8)   NOT NULL DEFAULT 0.00000000 COMMENT '资金费/借贷利息累计（正=付，负=收）',
    `liquidation_price`   DECIMAL(24,8)   NOT NULL DEFAULT 0.00000000 COMMENT '强平价（期货）',
    `min_rate`            DECIMAL(24,8)   NULL COMMENT '持仓期最低价',
    `max_rate`            DECIMAL(24,8)   NULL COMMENT '持仓期最高价',
    `nr_entries`          INT             NOT NULL DEFAULT 0 COMMENT '成功入场批次（DCA 次数）',
    `nr_exits`            INT             NOT NULL DEFAULT 0 COMMENT '成功平仓批次',
    `duration_minutes`    INT             NOT NULL DEFAULT 0 COMMENT '持仓时长（分钟）',
    `close_profit_abs`    DECIMAL(20,8)   NOT NULL DEFAULT 0.00000000 COMMENT '已实现盈亏绝对值（stake 货币）',
    `close_profit_ratio`  DECIMAL(12,8)   NOT NULL DEFAULT 0.00000000 COMMENT '相对 stake_amount 的收益率（小数）',
    `order_count`         INT             NOT NULL DEFAULT 0 COMMENT '关联订单数',
    `created_at`          DATETIME(3)     NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间（UTC）',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_btrades_backtest_trade` (`backtest_id`, `trade_id`),
    KEY `idx_btrades_pair` (`pair`),
    KEY `idx_btrades_reason` (`close_reason`),
    KEY `idx_btrades_open_time` (`open_timestamp_ms`),
    CONSTRAINT `fk_btrades_backtest` FOREIGN KEY (`backtest_id`)
        REFERENCES `backtests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='回测持仓成交明细';

-- 6. 回测订单明细表 ------------------------------------------------------------
-- 字段与 OrderRecord 对齐；一笔持仓可对应多笔订单（DCA 加仓/分批平仓/撤单）
CREATE TABLE IF NOT EXISTS `backtest_orders` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    `backtest_id`          BIGINT UNSIGNED NOT NULL COMMENT '关联 backtests.id',
    `trade_id`             INT             NULL COMMENT '回测内持仓 ID（被拒/孤立订单可为 NULL）',
    `order_id`             INT             NOT NULL COMMENT '回测内订单自增 ID',
    `exchange_order_id`    VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '交易所订单号（回测下为本地 id 字符串）',
    `symbol`               VARCHAR(32)     NOT NULL COMMENT '交易对',
    `side`                 ENUM('buy', 'sell') NOT NULL COMMENT '订单方向 buy/sell（注意与持仓方向区分）',
    `type`                 VARCHAR(20)     NOT NULL COMMENT '订单类型 limit/market/stop_loss/take_profit 等',
    `status`               VARCHAR(12)     NOT NULL DEFAULT 'open' COMMENT '状态 open/partial/closed/canceled/expired/rejected',
    `price`                DECIMAL(24,8)   NOT NULL COMMENT '下单价格（市价单存撮合基准价）',
    `amount`               DECIMAL(24,8)   NOT NULL COMMENT '下单数量（基础货币）',
    `filled`               DECIMAL(24,8)   NOT NULL DEFAULT 0.00000000 COMMENT '已成交数量',
    `average_price`        DECIMAL(24,8)   NOT NULL DEFAULT 0.00000000 COMMENT '平均成交价',
    `cost`                 DECIMAL(20,8)   NOT NULL DEFAULT 0.00000000 COMMENT '成交总成本（stake 货币）',
    `fee_cost`             DECIMAL(20,8)   NOT NULL DEFAULT 0.00000000 COMMENT '手续费总额（stake 货币）',
    `fee_currency`         VARCHAR(16)     NOT NULL DEFAULT '' COMMENT '手续费币种',
    `order_timestamp_ms`   BIGINT          NOT NULL COMMENT '下单时间（毫秒）',
    `filled_timestamp_ms`  BIGINT          NULL COMMENT '最后成交时间（毫秒）',
    `entry_side`           TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '是否入场单（1=入场 0=平仓）',
    `stop_price`           DECIMAL(24,8)   NULL COMMENT '触发价（止损/止盈单）',
    `stake_amount`         DECIMAL(20,8)   NULL COMMENT 'stake 投资额',
    `created_at`           DATETIME(3)     NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间（UTC）',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_borders_backtest_order` (`backtest_id`, `order_id`),
    KEY `idx_borders_backtest_trade` (`backtest_id`, `trade_id`),
    KEY `idx_borders_symbol_time` (`symbol`, `order_timestamp_ms`),
    KEY `idx_borders_status` (`status`),
    CONSTRAINT `fk_borders_backtest` FOREIGN KEY (`backtest_id`)
        REFERENCES `backtests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='回测订单明细';
