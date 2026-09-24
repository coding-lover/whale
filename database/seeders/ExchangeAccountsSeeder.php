<?php

/**
 * 交易所模拟账号 Seeder
 *
 * 生成 10 个 testnet 模拟账号（Binance 5 个 + OKX 5 个），
 * 覆盖 spot / margin / futures 三种账户类型。
 *
 * 🔴 安全红线：本 seeder 不写入任何 API Key / Secret 明文！
 *    只写入 credential_ref（.env 凭证前缀），如 BINANCE_DEMO1。
 *    真实密钥由开发者在 .env 里配置：
 *      BINANCE_DEMO1_API_KEY=xxx
 *      BINANCE_DEMO1_SECRET=xxx
 *      OKX_DEMO1_API_KEY=xxx
 *      OKX_DEMO1_SECRET=xxx
 *      OKX_DEMO1_PASSPHRASE=xxx
 *
 * 用法：
 *   php database/seeders/ExchangeAccountsSeeder.php
 *
 * 可重复执行：先清除 demo- 前缀的旧数据，再插入。
 */

use App\Models\ExchangeAccount;
use Sikelan\Core\Bootstrap;
use Sikelan\Framework;

require __DIR__ . '/../../sikelan/Core/Bootstrap.php';
Bootstrap::cli(dirname(__DIR__, 2), false);
Framework::getInstance();

// 清除旧模拟数据（只删 demo- 前缀，绝不误删正式账户）
ExchangeAccount::query()->where('name', 'like', 'demo-%')->delete();

// 10 个模拟账号定义
// 字段：name / exchange / account_type / credential_ref / testnet / is_default / remark
$accounts = [
    // ---- Binance 测试网（5 个）----
    [
        'name'           => 'demo-binance-spot-1',
        'exchange'       => 'binance',
        'account_type'   => 'spot',
        'credential_ref' => 'BINANCE_DEMO1',
        'testnet'        => true,
        'is_default'     => true,
        'remark'         => 'Binance 测试网现货账户 #1（默认）',
    ],
    [
        'name'           => 'demo-binance-spot-2',
        'exchange'       => 'binance',
        'account_type'   => 'spot',
        'credential_ref' => 'BINANCE_DEMO2',
        'testnet'        => true,
        'is_default'     => false,
        'remark'         => 'Binance 测试网现货账户 #2',
    ],
    [
        'name'           => 'demo-binance-futures-1',
        'exchange'       => 'binance',
        'account_type'   => 'futures',
        'credential_ref' => 'BINANCE_DEMO3',
        'testnet'        => true,
        'is_default'     => false,
        'remark'         => 'Binance 测试网 U 本位永续合约账户 #1',
    ],
    [
        'name'           => 'demo-binance-futures-2',
        'exchange'       => 'binance',
        'account_type'   => 'futures',
        'credential_ref' => 'BINANCE_DEMO4',
        'testnet'        => true,
        'is_default'     => false,
        'remark'         => 'Binance 测试网 U 本位永续合约账户 #2',
    ],
    [
        'name'           => 'demo-binance-margin-1',
        'exchange'       => 'binance',
        'account_type'   => 'margin',
        'credential_ref' => 'BINANCE_DEMO5',
        'testnet'        => true,
        'is_default'     => false,
        'remark'         => 'Binance 测试网杠杆账户 #1',
    ],
    // ---- OKX 测试网（5 个）----
    [
        'name'           => 'demo-okx-spot-1',
        'exchange'       => 'okx',
        'account_type'   => 'spot',
        'credential_ref' => 'OKX_DEMO1',
        'testnet'        => true,
        'is_default'     => false,
        'remark'         => 'OKX 模拟盘现货账户 #1',
    ],
    [
        'name'           => 'demo-okx-spot-2',
        'exchange'       => 'okx',
        'account_type'   => 'spot',
        'credential_ref' => 'OKX_DEMO2',
        'testnet'        => true,
        'is_default'     => false,
        'remark'         => 'OKX 模拟盘现货账户 #2',
    ],
    [
        'name'           => 'demo-okx-futures-1',
        'exchange'       => 'okx',
        'account_type'   => 'futures',
        'credential_ref' => 'OKX_DEMO3',
        'testnet'        => true,
        'is_default'     => false,
        'remark'         => 'OKX 模拟盘永续合约账户 #1',
    ],
    [
        'name'           => 'demo-okx-futures-2',
        'exchange'       => 'okx',
        'account_type'   => 'futures',
        'credential_ref' => 'OKX_DEMO4',
        'testnet'        => true,
        'is_default'     => false,
        'remark'         => 'OKX 模拟盘永续合约账户 #2',
    ],
    [
        'name'           => 'demo-okx-margin-1',
        'exchange'       => 'okx',
        'account_type'   => 'margin',
        'credential_ref' => 'OKX_DEMO5',
        'testnet'        => true,
        'is_default'     => false,
        'remark'         => 'OKX 模拟盘杠杆账户 #1',
    ],
];

// 公共配置：测试网默认开启 SSL 校验、不启用代理
$common = [
    'ssl_verify'    => true,
    'proxy_enabled' => false,
    'proxy_host'    => null,
    'proxy_port'    => null,
    'status'        => 'enabled',
];

$count = 0;
foreach ($accounts as $acct) {
    ExchangeAccount::create(array_merge($common, $acct));
    $count++;
}

echo "✓ 已生成 {$count} 个交易所模拟账号" . PHP_EOL;
echo PHP_EOL;
echo '下一步：在 .env 文件中为每个 credential_ref 配置真实密钥，' . PHP_EOL;
echo '例如 BINANCE_DEMO1 对应：' . PHP_EOL;
echo '  BINANCE_DEMO1_API_KEY=...' . PHP_EOL;
echo '  BINANCE_DEMO1_SECRET=...' . PHP_EOL;
echo 'OKX 还需要 PASSPHRASE：' . PHP_EOL;
echo '  OKX_DEMO1_API_KEY=...' . PHP_EOL;
echo '  OKX_DEMO1_SECRET=...' . PHP_EOL;
echo '  OKX_DEMO1_PASSPHRASE=...' . PHP_EOL;
