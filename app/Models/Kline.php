<?php

namespace App\Models;

/**
 * K 线行情模型（klines）
 *
 * 对应 App\Services\Trader\Market\Candle，是回测/模拟/实盘共用的行情数据源。
 * 唯一键 (exchange, symbol, timeframe, open_time_ms)，批量导入用 updateOrCreate 防重。
 *
 * 注意：价格/量 DECIMAL 列默认以 string 返回（保金融精度），数学运算前由业务层 (float) 转换。
 */
class Kline extends Model
{
    /** @var string */
    protected $table = 'klines';

    /**
     * 可批量赋值字段白名单
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'exchange',
        'symbol',
        'timeframe',
        'open_time_ms',
        'open',
        'high',
        'low',
        'close',
        'volume',
    ];

    /**
     * 时间戳字段类型转换
     *
     * @var array<string, string>
     */
    protected $casts = [
        'open_time_ms' => 'integer',
    ];
}
