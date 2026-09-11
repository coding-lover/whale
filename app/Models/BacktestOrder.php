<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 回测订单明细模型（backtest_orders）
 *
 * 字段与 App\Services\Trader\Model\OrderRecord 对齐。
 * 一笔持仓（BacktestTrade）可对应多笔订单：DCA 加仓、分批平仓、撤单等。
 *
 * 注意：DECIMAL 列默认以 string 返回，数学运算前由业务层 (float) 转换。
 */
class BacktestOrder extends Model
{
    /** @var string */
    protected $table = 'backtest_orders';

    /**
     * 可批量赋值字段白名单
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'backtest_id',
        'trade_id',
        'order_id',
        'exchange_order_id',
        'symbol',
        'side',
        'type',
        'status',
        'price',
        'amount',
        'filled',
        'average_price',
        'cost',
        'fee_cost',
        'fee_currency',
        'order_timestamp_ms',
        'filled_timestamp_ms',
        'entry_side',
        'stop_price',
        'stake_amount',
    ];

    /**
     * 类型转换
     *
     * @var array<string, string>
     */
    protected $casts = [
        'backtest_id'         => 'integer',
        'trade_id'            => 'integer',
        'order_id'            => 'integer',
        'entry_side'          => 'boolean',
        'order_timestamp_ms'  => 'integer',
        'filled_timestamp_ms' => 'integer',
    ];

    /**
     * 所属回测
     *
     * @return BelongsTo
     */
    public function backtest(): BelongsTo
    {
        return $this->belongsTo(Backtest::class);
    }
}
