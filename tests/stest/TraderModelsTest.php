<?php

namespace Sikelan\Tests\Stest;

use App\Models\Backtest;
use App\Models\BacktestOrder;
use App\Models\BacktestTrade;
use App\Models\ExchangeAccount;
use App\Models\Kline;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use PHPUnit\Framework\TestCase;
use Sikelan\Core\Config;
use Sikelan\Database\EloquentManager;
use Sikelan\Database\PdoPool;

/**
 * 首批交易核心表 Eloquent 模型结构测试
 *
 * 与 EloquentTest 风格保持一致：只验证模型声明（表名/白名单/cast/关联/软删除），
 * boot() 使用懒连接池，关联关系对象实例化不触发真实 MySQL 查询。
 *
 * 真实 CRUD / 外键级联 / 唯一键防重由建表后的冒烟脚本人工验证：
 *   - JSON 列往返、bool/int cast
 *   - backtests→trades/orders 级联删除，strategy 删除置空
 *   - klines 唯一键 updateOrCreate 防重
 */
class TraderModelsTest extends TestCase
{
    protected function setUp(): void
    {
        // boot 全局连接解析器（PdoPool 懒加载，结构断言阶段不产生真实连接）
        $config = new Config();
        $config->set('database.mysql', [
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'root',
            'password' => 'secret',
            'database' => 'sikelan_test',
            'charset' => 'utf8mb4',
            'timeout' => 5,
            'pool_size' => 10,
        ]);
        (new EloquentManager($config, new PdoPool($config)))->boot();
    }
    public function testStrategyMapsTableAndCasts()
    {
        $m = new Strategy();

        $this->assertSame('strategies', $m->getTable());
        $this->assertContains('alias', $m->getFillable());
        $this->assertContains('class_name', $m->getFillable());
        $this->assertSame('array', $m->getCasts()['params']);
        $this->assertSame('boolean', $m->getCasts()['can_short']);
        $this->assertInstanceOf(HasMany::class, $m->backtests());
    }

    public function testExchangeAccountMapsTableAndBooleanCasts()
    {
        $m = new ExchangeAccount();

        $this->assertSame('exchange_accounts', $m->getTable());
        $this->assertContains('credential_ref', $m->getFillable());
        // 安全红线：密钥字段绝不可出现在白名单中
        $this->assertNotContains('api_key', $m->getFillable());
        $this->assertNotContains('secret', $m->getFillable());
        $this->assertNotContains('passphrase', $m->getFillable());

        $casts = $m->getCasts();
        $this->assertSame('boolean', $casts['testnet']);
        $this->assertSame('boolean', $casts['ssl_verify']);
        $this->assertSame('boolean', $casts['proxy_enabled']);
        $this->assertSame('boolean', $casts['is_default']);
    }

    public function testKlineMapsTableAndOhlcvFillable()
    {
        $m = new Kline();

        $this->assertSame('klines', $m->getTable());
        $klineCols = ['exchange', 'symbol', 'timeframe', 'open_time_ms', 'open', 'high', 'low', 'close', 'volume'];
        foreach ($klineCols as $col) {
            $this->assertContains($col, $m->getFillable(), "klines 缺少可填充字段 {$col}");
        }
        $this->assertSame('integer', $m->getCasts()['open_time_ms']);
    }

    public function testBacktestMapsTableCastsRelationsAndSoftDeletes()
    {
        $m = new Backtest();

        $this->assertSame('backtests', $m->getTable());

        // 关键输入与绩效列可填充
        $backtestCols = [
            'strategy_id', 'symbols', 'initial_capital',
            'sharpe_ratio', 'total_trades', 'equity_curve',
        ];
        foreach ($backtestCols as $col) {
            $this->assertContains($col, $m->getFillable(), "backtests 缺少可填充字段 {$col}");
        }

        // 4 个 JSON 列必须转 array
        $casts = $m->getCasts();
        foreach (['symbols', 'params', 'equity_curve', 'metrics'] as $jsonCol) {
            $this->assertSame('array', $casts[$jsonCol], "{$jsonCol} 应 cast 为 array");
        }

        // 软删除 + 状态常量
        $this->assertContains(SoftDeletes::class, class_uses_recursive(Backtest::class));
        $this->assertSame('running', Backtest::STATUS_RUNNING);
        $this->assertSame('completed', Backtest::STATUS_COMPLETED);
        $this->assertSame('failed', Backtest::STATUS_FAILED);

        // 关联
        $this->assertInstanceOf(BelongsTo::class, $m->strategy());
        $this->assertInstanceOf(HasMany::class, $m->trades());
        $this->assertInstanceOf(HasMany::class, $m->orders());
    }

    public function testBacktestTradeMapsFieldsAndRelation()
    {
        $m = new BacktestTrade();

        $this->assertSame('backtest_trades', $m->getTable());
        // 与 TradeRecord::toArray() 对齐的核心字段
        $tradeCols = [
            'backtest_id', 'trade_id', 'pair', 'direction', 'stake_amount',
            'open_rate', 'close_rate', 'close_reason', 'close_profit_abs',
            'close_profit_ratio', 'open_timestamp_ms', 'close_timestamp_ms',
        ];
        foreach ($tradeCols as $col) {
            $this->assertContains($col, $m->getFillable(), "backtest_trades 缺少字段 {$col}");
        }
        $this->assertSame('boolean', $m->getCasts()['is_open']);
        $this->assertInstanceOf(BelongsTo::class, $m->backtest());
    }

    public function testBacktestOrderMapsFieldsAndRelation()
    {
        $m = new BacktestOrder();

        $this->assertSame('backtest_orders', $m->getTable());
        // 与 OrderRecord 对齐的核心字段
        $orderCols = [
            'backtest_id', 'order_id', 'exchange_order_id', 'symbol', 'side',
            'type', 'status', 'price', 'amount', 'filled', 'average_price',
            'fee_cost', 'order_timestamp_ms', 'filled_timestamp_ms',
        ];
        foreach ($orderCols as $col) {
            $this->assertContains($col, $m->getFillable(), "backtest_orders 缺少字段 {$col}");
        }
        $this->assertSame('boolean', $m->getCasts()['entry_side']);
        $this->assertInstanceOf(BelongsTo::class, $m->backtest());
    }
}
