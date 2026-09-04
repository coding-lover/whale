<?php

/**
 * 交易所服务配置
 *
 * 配置 Binance 和 OKX 的 API 参数、测试网和速率限制
 * 支持多环境配置：dev 环境可使用测试网，prod 环境使用正式环境
 */

return [

    // 默认交易所（不设置则自动使用第一个配置了 API Key 的交易所）
    'default' => env('EXCHANGE_DEFAULT', 'binance'),

    // HTTP 代理配置（用于访问被墙的交易所 API）
    'proxy' => [
        'enabled' => env('EXCHANGE_PROXY_ENABLED', true),
        'host' => env('EXCHANGE_PROXY_HOST', '127.0.0.1'),
        'port' => env('EXCHANGE_PROXY_PORT', 6666),
    ],

    // 调试日志开关（记录 HTTP 请求详情，便于排查代理连接问题）
    'debug_log' => env('EXCHANGE_DEBUG_LOG', false),

    // Binance 配置
    // ⭐ 多市场（现货 / U本位永续 / 币本位合约）独立配置。
    // 「SPOT 默认值」完全沿用旧的 BINANCE_* 环境变量（100% 向后兼容）；
    // USD-M / COIN-M 的凭证与域名优先从 *_FUT_* / *_COIN_* 变量取，缺省时回退到 BINANCE_*（个人项目通常一套 key 多市场通用）。
    // 注意：Binance 3 市场 base_url / path 前缀完全不同，不能混用：
    //   SPOT:   https://api.binance.com       /api/v3/*
    //   USD-M:  https://fapi.binance.com      /fapi/v1/*
    //   COIN-M: https://dapi.binance.com      /dapi/v1/*
    'binance' => [
        // 为了 100% 向后兼容（旧代码仍然使用 AbstractExchange::getBaseUrl()/getApiKey()/getSecret()
        //   这些只认「顶层配置键」），顶层字段保留为 SPOT 的配置，并作为其他两个市场的 fallback。
        'base_url'    => env('BINANCE_BASE_URL', env('BINANCE_SPOT_BASE_URL', 'https://api.binance.com')),
        'testnet'     => env('BINANCE_TESTNET', false),
        'testnet_url' => env('BINANCE_TESTNET_URL', env('BINANCE_SPOT_TESTNET_URL', 'https://testnet.binance.vision')),
        'api_key'     => env('BINANCE_API_KEY',    env('BINANCE_SPOT_API_KEY', '')),
        'secret'      => env('BINANCE_SECRET',     env('BINANCE_SPOT_SECRET', '')),
        'rate_limit_ms' => 100,
        'ssl_verify'  => env('BINANCE_SSL_VERIFY', true),

        // ---- 三个市场分别可覆盖 ----
        'markets' => [
            // · 现货（SPOT）：显式列出用于路由；默认全部继承顶层
            'spot' => [
                // 覆盖优先级：本项字段 > 顶层字段 > 文档默认值
                'base_url'    => env('BINANCE_SPOT_BASE_URL', null), // null 表示继承顶层
                'testnet_url' => env('BINANCE_SPOT_TESTNET_URL', null),
                'api_key'     => env('BINANCE_SPOT_API_KEY', null),
                'secret'      => env('BINANCE_SPOT_SECRET', null),
                'ssl_verify'  => env('BINANCE_SPOT_SSL_VERIFY', null),
                'path_prefix' => '/api/v3',   // Spot 公共/私有接口前缀（balance 例外走 /sapi/v1/capital/config/getall 暂不）
            ],
            // · U本位永续 & 交割 (USDⓈ-M)：BTCUSDT / BTCUSDT_250627 等
            'usd_m' => [
                'base_url'    => env('BINANCE_FUT_BASE_URL', 'https://fapi.binance.com'),
                'testnet_url' => env('BINANCE_FUT_TESTNET_URL', 'https://demo-fapi.binance.com'),
                'testnet'     => env('BINANCE_FUT_TESTNET', null),   // null = 继承顶层 testnet 开关
                'api_key'     => env('BINANCE_FUT_API_KEY', null),   // null = 继承顶层 BINANCE_API_KEY
                'secret'      => env('BINANCE_FUT_SECRET', null),
                'ssl_verify'  => env('BINANCE_FUT_SSL_VERIFY', null),
                'path_prefix' => '/fapi/v1',
            ],
            // · 币本位合约 (COIN-M)：BTCUSD_PERP / BTCUSD_250627 / BTCUSD_QUARTER 等
            'coin_m' => [
                'base_url'    => env('BINANCE_COIN_BASE_URL', 'https://dapi.binance.com'),
                'testnet_url' => env('BINANCE_COIN_TESTNET_URL', 'https://demo-dapi.binance.com'),
                'testnet'     => env('BINANCE_COIN_TESTNET', null),
                'api_key'     => env('BINANCE_COIN_API_KEY', null),
                'secret'      => env('BINANCE_COIN_SECRET', null),
                'ssl_verify'  => env('BINANCE_COIN_SSL_VERIFY', null),
                'path_prefix' => '/dapi/v1',
            ],
        ],
    ],

    // OKX 配置
    'okx' => [
        // 正式环境 API
        'base_url' => 'https://www.okx.com',
        // 模拟盘（OKX 称测试网为模拟盘）
        'testnet' => env('OKX_TESTNET', false),
        'testnet_url' => 'https://www.okx.com', // OKX 模拟盘需单独申请

        // API 凭证（OKX 额外需要 passphrase）
        'api_key' => env('OKX_API_KEY', ''),
        'secret' => env('OKX_SECRET', ''),
        'passphrase' => env('OKX_PASSPHRASE', ''),

        // 速率限制（毫秒）：OKX 默认 20 次/2秒 ≈ 100ms
        'rate_limit_ms' => 100,

        // SSL 证书验证（本地开发/测试环境可设为 false）
        'ssl_verify' => env('OKX_SSL_VERIFY', true),
    ],

    // 自定义交易所适配器（可选）
    // 'custom_adapters' => [
    //     'gate' => \App\Services\Exchanges\GateExchange::class,
    // ],

];
