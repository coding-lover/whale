<?php

namespace App\Models;

/**
 * 交易所账户模型（exchange_accounts）
 *
 * 🔴 安全红线：表内不存 API Key / Secret / Passphrase 明文，
 * 只保存 credential_ref（.env 凭证前缀，如 BINANCE），
 * 运行时通过 env('BINANCE_API_KEY') / env('BINANCE_SECRET') 读取。
 */
class ExchangeAccount extends Model
{
    /** @var string */
    protected $table = 'exchange_accounts';

    /**
     * 可批量赋值字段白名单
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'exchange',
        'account_type',
        'credential_ref',
        'testnet',
        'ssl_verify',
        'proxy_enabled',
        'proxy_host',
        'proxy_port',
        'is_default',
        'status',
        'remark',
    ];

    /**
     * 布尔字段类型转换
     *
     * @var array<string, string>
     */
    protected $casts = [
        'testnet'       => 'boolean',
        'ssl_verify'    => 'boolean',
        'proxy_enabled' => 'boolean',
        'is_default'    => 'boolean',
        'proxy_port'    => 'integer',
    ];
}
