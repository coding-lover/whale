<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 策略注册表模型（strategies）
 *
 * config/trader.php 中策略别名的数据库镜像，记录策略类名、构造参数与默认配置。
 * 回测记录只冗余保存 alias/name/version 快照，策略后续修改不影响历史回测。
 */
class Strategy extends Model
{
    /** @var string */
    protected $table = 'strategies';

    /**
     * 可批量赋值字段白名单
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'alias',
        'name',
        'class_name',
        'version',
        'params',
        'default_timeframe',
        'trading_mode',
        'can_short',
        'description',
        'status',
    ];

    /**
     * 类型转换：params 构造参数为 JSON 数组，can_short 为布尔
     *
     * @var array<string, string>
     */
    protected $casts = [
        'params'    => 'array',
        'can_short' => 'boolean',
    ];

    /**
     * 该策略下的所有回测记录
     *
     * @return HasMany
     */
    public function backtests(): HasMany
    {
        return $this->hasMany(Backtest::class);
    }
}
