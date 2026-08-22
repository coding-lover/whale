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
    'binance' => [
        // 正式环境 API
        'base_url' => 'https://api.binance.com',
        // 测试网（设为 true 时使用 testnet_url）
        'testnet' => env('BINANCE_TESTNET', false),
        'testnet_url' => 'https://testnet.binance.vision',

        // API 凭证（建议从 .env 读取）
        'api_key' => env('BINANCE_API_KEY', 'xx'),
        'secret' => env('BINANCE_SECRET', 'xxxx '),

        // 速率限制（毫秒）：Binance 默认 10 次/秒 = 100ms
        'rate_limit_ms' => 100,

        // SSL 证书验证（本地开发/测试环境可设为 false）
        'ssl_verify' => env('BINANCE_SSL_VERIFY', true),
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
