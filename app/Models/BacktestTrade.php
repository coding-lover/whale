<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 回测持仓成交明细模型（backtest_trades）
 *
 * 字段与 App\Services\Trader\Model\TradeRecord::toArray() 一一对应，
 * TradeRecord 导出数组可直接用于本模型的批量写入。
 *
 * 注意：DECIMAL 列默认以 string 返回，数学运算前由业务层 (float) 转换。
 */
class BacktestTrade extends Model
{
    /** @var string */
    protected $table = 'backtest_trades';

    /**
     * 可批量赋值字段白名单
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'backtest_id',
        'trade_id',
        'pair',
        'direction',
        'trading_mode',
        'leverage',
        'stake_amount',
        'amount',
        'open_rate',
        'close_rate',
        'open_timestamp_ms',
        'close_timestamp_ms',
        'is_open',
        'close_reason',
        'enter_tag',
        'exit_tag',
        'fee_open',
        'fee_close',
        'funding_interest',
        'liquidation_price',
        'min_rate',
        'max_rate',
        'nr_entries',
        'nr_exits',
        'duration_minutes',
        'close_profit_abs',
        'close_profit_ratio',
        'order_count',
    ];

    /**
     * 类型转换
     *
     * @var array<string, string>
     */
    protected $casts = [
        'backtest_id'        => 'integer',
        'trade_id'           => 'integer',
        'is_open'            => 'boolean',
        'open_timestamp_ms'  => 'integer',
        'close_timestamp_ms' => 'integer',
        'nr_entries'         => 'integer',
        'nr_exits'           => 'integer',
        'duration_minutes'   => 'integer',
        'order_count'        => 'integer',
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
