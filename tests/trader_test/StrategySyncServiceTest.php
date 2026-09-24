<?php

namespace Sikelan\Tests\trader_test;

use App\Models\Strategy;
use App\Services\Trader\Strategies\BollingerRsiMeanReversionStrategy;
use App\Services\Trader\Strategies\EmaCrossStrategy;
use App\Services\Trader\Strategies\WmcStrategy;
use App\Services\Trader\StrategySyncService;
use PHPUnit\Framework\TestCase;
use Sikelan\Core\Config;
use Sikelan\Database\EloquentManager;
use Sikelan\Database\PdoPool;

/**
 * StrategySyncService 策略同步服务集成测试（真实 MySQL：quant_trade.strategies）
 *
 * 所有测试别名统一前缀 SyncTest，setUp 时清理，绝不污染正式策略数据。
 *
 * 覆盖：
 *   1. upsert 新增 / 再同步无变化 / 参数变化更新 / 换类更新
 *   2. 手动 disabled 的 status 不被同步重置
 *   3. 非法类 / 构造参数非法 → skipped（不抛异常）
 *   4. dry-run 不落库
 *   5. sync() 注册表批量同步统计
 *   6. 目录扫描：去重 / 发现未注册类 / 配置优先级
 */
class StrategySyncServiceTest extends TestCase
{
    private const TEST_PREFIX = 'SyncTest';

    private static bool $booted = false;

    protected function setUp(): void
    {
        if (!self::$booted) {
            $config = new Config();
            $config->set('database.mysql', [
                'host'     => '127.0.0.1',
                'port'     => 3306,
                'username' => 'df',
                'password' => 'aa123456',
                'database' => 'quant_trade',
                'charset'  => 'utf8mb4',
                'timeout'  => 5,
                'pool_size'=> 10,
            ]);
            (new EloquentManager($config, new PdoPool($config)))->boot();
            self::$booted = true;
        }
        // 每个用例前清掉残留测试数据
        Strategy::query()->where('alias', 'like', self::TEST_PREFIX . '%')->delete();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$booted) {
            Strategy::query()->where('alias', 'like', self::TEST_PREFIX . '%')->delete();
        }
    }

    private function service(): StrategySyncService
    {
        return new StrategySyncService();
    }

    // ----------------------------------------------------------------
    //  1. 单条 upsert：新增 / 无变化 / 更新
    // ----------------------------------------------------------------

    public function testUpsertCreatesNewStrategy(): void
    {
        $result = $this->service()->upsertEntry(
            self::TEST_PREFIX . 'Ema',
            EmaCrossStrategy::class,
            [3, 7, 0.001]
        );
        $this->assertSame('created', $result);

        $row = Strategy::query()->where('alias', self::TEST_PREFIX . 'Ema')->first();
        $this->assertNotNull($row);
        $this->assertSame(EmaCrossStrategy::class, $row->class_name);
        $this->assertSame([3, 7, 0.001], $row->params);
        $this->assertFalse($row->can_short);
        $this->assertSame('enabled', $row->status);
        $this->assertNotEmpty($row->version);
        $this->assertNotEmpty($row->name);
    }

    public function testSecondSyncReportsUnchanged(): void
    {
        $svc = $this->service();
        $alias = self::TEST_PREFIX . 'Again';

        $this->assertSame('created', $svc->upsertEntry($alias, EmaCrossStrategy::class, [3, 7, 0.001]));
        $this->assertSame('unchanged', $svc->upsertEntry($alias, EmaCrossStrategy::class, [3, 7, 0.001]));
    }

    public function testUpdateWhenConstructParamsChanged(): void
    {
        $svc = $this->service();
        $alias = self::TEST_PREFIX . 'Params';

        $svc->upsertEntry($alias, EmaCrossStrategy::class, [3, 7, 0.001]);
        $result = $svc->upsertEntry($alias, EmaCrossStrategy::class, [5, 10, 0.002]);

        $this->assertSame('updated', $result);
        $row = Strategy::query()->where('alias', $alias)->first();
        $this->assertSame([5, 10, 0.002], $row->params);
    }

    public function testUpdateWhenClassChanged(): void
    {
        $svc = $this->service();
        $alias = self::TEST_PREFIX . 'Switch';

        $svc->upsertEntry($alias, EmaCrossStrategy::class, []);
        $result = $svc->upsertEntry($alias, BollingerRsiMeanReversionStrategy::class, []);

        $this->assertSame('updated', $result);
        $row = Strategy::query()->where('alias', $alias)->first();
        $this->assertSame(BollingerRsiMeanReversionStrategy::class, $row->class_name);
    }

    public function testManuallyDisabledStatusIsPreservedOnResync(): void
    {
        $svc = $this->service();
        $alias = self::TEST_PREFIX . 'Disabled';

        $svc->upsertEntry($alias, EmaCrossStrategy::class, [3, 7, 0.001]);
        $row = Strategy::query()->where('alias', $alias)->first();
        $row->status = 'disabled';
        $row->save();

        // 再同步一次：参数没变 → unchanged；即便参数变化，status 也必须保留 disabled
        $this->assertSame('unchanged', $svc->upsertEntry($alias, EmaCrossStrategy::class, [3, 7, 0.001]));
        $this->assertSame('disabled', Strategy::query()->where('alias', $alias)->first()->status);

        $this->assertSame('updated', $svc->upsertEntry($alias, EmaCrossStrategy::class, [4, 9, 0.002]));
        $this->assertSame('disabled', Strategy::query()->where('alias', $alias)->first()->status);
    }

    // ----------------------------------------------------------------
    //  2. 异常路径：非法类 / 构造失败 / dry-run
    // ----------------------------------------------------------------

    public function testUpsertSkipsNonexistentClass(): void
    {
        $result = $this->service()->upsertEntry(
            self::TEST_PREFIX . 'NoClass',
            'App\\Services\\Trader\\Strategies\\NotExistsAtAll',
            []
        );
        $this->assertStringStartsWith('skipped:', $result);
        $this->assertNull(Strategy::query()->where('alias', self::TEST_PREFIX . 'NoClass')->first());
    }

    public function testUpsertSkipsWhenConstructFails(): void
    {
        // EmaCrossStrategy 构造器要求 short < long，[50, 10] 非法会抛 InvalidArgumentException
        $result = $this->service()->upsertEntry(
            self::TEST_PREFIX . 'BadArgs',
            EmaCrossStrategy::class,
            [50, 10, 0.001]
        );
        $this->assertStringStartsWith('skipped:', $result);
        $this->assertStringContainsString('实例化失败', $result);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $alias = self::TEST_PREFIX . 'Dry';
        $result = $this->service()->upsertEntry($alias, EmaCrossStrategy::class, [], [], true);
        $this->assertSame('created', $result);
        $this->assertNull(Strategy::query()->where('alias', $alias)->first());
    }

    // ----------------------------------------------------------------
    //  3. sync() 批量同步
    // ----------------------------------------------------------------

    public function testSyncBatchCreatesAndReportsStats(): void
    {
        $registry = [
            self::TEST_PREFIX . 'Batch1' => ['class' => EmaCrossStrategy::class, 'construct' => [3, 7, 0.001]],
            self::TEST_PREFIX . 'Batch2' => ['class' => BollingerRsiMeanReversionStrategy::class, 'construct' => []],
        ];
        $stats = $this->service()->sync($registry, ['no_scan' => true]);

        $this->assertContains(self::TEST_PREFIX . 'Batch1', $stats['created']);
        $this->assertContains(self::TEST_PREFIX . 'Batch2', $stats['created']);
        $this->assertSame([], $stats['skipped']);

        // 再来一次 → 全部无变化
        $stats2 = $this->service()->sync($registry, ['no_scan' => true]);
        $this->assertContains(self::TEST_PREFIX . 'Batch1', $stats2['unchanged']);
        $this->assertContains(self::TEST_PREFIX . 'Batch2', $stats2['unchanged']);
    }

    // ----------------------------------------------------------------
    //  4. 目录扫描
    // ----------------------------------------------------------------

    public function testScanDirectoryReturnsEmptyWhenDirMissing(): void
    {
        $this->assertSame([], $this->service()->scanDirectory('/path/does/not/exist'));
    }

    public function testScanDirectoryExcludesRegisteredClasses(): void
    {
        $svc = $this->service();
        $dir = APP_PATH . '/Services/Trader/Strategies';

        // 三个真实类全部已注册 → 扫描结果为空
        $allRegistered = [
            'EmaCross20_50' => ['class' => EmaCrossStrategy::class, 'construct' => [20, 50, 0.003]],
            'MeanRevStd'    => ['class' => BollingerRsiMeanReversionStrategy::class, 'construct' => []],
            'WmcStrategy'   => ['class' => WmcStrategy::class, 'construct' => [12, 60, 0.005]],
        ];
        $this->assertSame([], $svc->scanDirectory($dir, $allRegistered));

        // 只排除其余两个 → EmaCross 被发现，别名去掉 Strategy 后缀
        $partial = [
            'MeanRevStd'  => ['class' => BollingerRsiMeanReversionStrategy::class, 'construct' => []],
            'WmcStrategy' => ['class' => WmcStrategy::class, 'construct' => []],
        ];
        $found = $svc->scanDirectory($dir, $partial);
        $this->assertArrayHasKey('EmaCross', $found);
        $this->assertSame(EmaCrossStrategy::class, $found['EmaCross']['class']);
        $this->assertSame([], $found['EmaCross']['construct']);
    }

    public function testConfigRegistryWinsOverScannedAlias(): void
    {
        // 扫描目录会得到别名 'EmaCross'（EmaCrossStrategy），
        // 若 config 注册表里也声明了 'EmaCross'（指向别的类），以 config 为准。
        // 注意：目录扫描还会补充注册表中不存在的其他类（如 Wmc），用例结束统一清理。
        $registry = [
            'EmaCross' => ['class' => BollingerRsiMeanReversionStrategy::class, 'construct' => []],
        ];
        $stats = $this->service()->sync($registry);

        try {
            $this->assertContains('EmaCross', $stats['created']);
            $this->assertSame(
                BollingerRsiMeanReversionStrategy::class,
                Strategy::query()->where('alias', 'EmaCross')->first()->class_name
            );
        } finally {
            // 清理本次写入的所有非前缀别名（config 别名 + 目录扫描补充别名）
            $created = array_merge($stats['created'], $stats['updated']);
            foreach ($created as $alias) {
                Strategy::query()->where('alias', $alias)->delete();
            }
        }
    }
}
