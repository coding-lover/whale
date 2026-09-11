<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 回测任务结果主表模型（backtests）
 *
 * 一条记录 = 一次回测：输入参数（交易所/交易对/周期/资金/费率）+
 * PerformanceReport 的 30+ 项绩效汇总 + 权益曲线 JSON。
 *
 * 写入位置建议：回测执行结束后，由应用服务把 ResultExporter::toArray()
 * 的 summary/meta 映射到独立列，equity_curve 整体写入 JSON 列。
 */
class Backtest extends Model
{
    use SoftDeletes;

    /** @var string 运行中 */
    public const STATUS_RUNNING = 'running';
    /** @var string 已完成 */
    public const STATUS_COMPLETED = 'completed';
    /** @var string 失败 */
    public const STATUS_FAILED = 'failed';

    /** @var string */
    protected $table = 'backtests';

    /**
     * 可批量赋值字段白名单（id/时间戳/deleted_at 由 DB 与 SoftDeletes 管理）
     *
     * @var array<int, string>
     */
    protected $fillable = [
        // 关联与策略快照
        'strategy_id',
        'strategy_alias',
        'strategy_name',
        'strategy_version',
        // 输入参数
        'exchange',
        'symbols',
        'timeframe',
        'run_mode',
        'trading_mode',
        'stake_currency',
        'start_ms',
        'end_ms',
        'initial_capital',
        'final_capital',
        'warmup_candles',
        'fee_maker',
        'fee_taker',
        'slippage_pct',
        'params',
        // 绩效汇总
        'total_net_profit',
        'total_return_pct',
        'cagr_pct',
        'sharpe_ratio',
        'sortino_ratio',
        'calmar_ratio',
        'max_drawdown_pct',
        'max_drawdown_abs',
        'mdd_peak_ms',
        'mdd_start_ms',
        'mdd_end_ms',
        'total_trades',
        'signals_total',
        'signals_rejected',
        'win_count',
        'loss_count',
        'win_rate_pct',
        'avg_trade_pct',
        'avg_win_pct',
        'avg_loss_pct',
        'profit_factor',
        'profit_loss_ratio',
        'best_trade_pct',
        'worst_trade_pct',
        'avg_duration_min',
        'expectancy_abs',
        // 大字段与运行状态
        'equity_curve',
        'metrics',
        'status',
        'error_message',
        'duration_ms',
    ];

    /**
     * 类型转换：JSON 列转数组，计数字段转 int
     *
     * @var array<string, string>
     */
    protected $casts = [
        'symbols'          => 'array',
        'params'           => 'array',
        'equity_curve'     => 'array',
        'metrics'          => 'array',
        'strategy_id'      => 'integer',
        'start_ms'         => 'integer',
        'end_ms'           => 'integer',
        'warmup_candles'   => 'integer',
        'mdd_peak_ms'      => 'integer',
        'mdd_start_ms'     => 'integer',
        'mdd_end_ms'       => 'integer',
        'total_trades'     => 'integer',
        'signals_total'    => 'integer',
        'signals_rejected' => 'integer',
        'win_count'        => 'integer',
        'loss_count'       => 'integer',
        'duration_ms'      => 'integer',
    ];

    /**
     * 所属策略（策略删除时 strategy_id 置 NULL，回测历史保留）
     *
     * @return BelongsTo
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    /**
     * 回测持仓成交明细（回测删除时级联删除）
     *
     * @return HasMany
     */
    public function trades(): HasMany
    {
        return $this->hasMany(BacktestTrade::class);
    }

    /**
     * 回测订单明细
     *
     * @return HasMany
     */
    public function orders(): HasMany
    {
        return $this->hasMany(BacktestOrder::class);
    }
}
