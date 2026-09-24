<?php

namespace Sikelan\Tests\trader_test;

use App\Commands\TraderSyncStrategiesCommand;
use App\Models\Strategy;
use PHPUnit\Framework\TestCase;
use Sikelan\Command\CommandManager;
use Sikelan\Command\CommandRunner;
use Sikelan\Framework;

/**
 * trader:sync-strategies 命令测试
 *
 * 注意：--dry-run 会读库比对但绝不写库，适合在真实库上验证命令链路。
 */
class TraderSyncStrategiesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        // 命令内部也会调用 Framework::getInstance()；测试自身需要读库做前后对比，先引导
        Framework::getInstance();
    }

    protected function tearDown(): void
    {
        // 重置单例避免 argv 污染其他用例
        foreach ([CommandManager::class, CommandRunner::class] as $cls) {
            $reflection = new \ReflectionClass($cls);
            if ($reflection->hasProperty('instance')) {
                $prop = $reflection->getProperty('instance');
                $prop->setAccessible(true);
                $prop->setValue(null, null);
            }
        }
        parent::tearDown();
    }

    public function testCommandAutoRegistered(): void
    {
        CommandRunner::getInstance();
        $m = CommandManager::getInstance();
        $this->assertTrue($m->hasCommand('trader:sync-strategies'));
        $this->assertInstanceOf(
            TraderSyncStrategiesCommand::class,
            $m->getCommand('trader:sync-strategies')
        );
    }

    public function testDescAndHelpCoverOptions(): void
    {
        $cmd = new TraderSyncStrategiesCommand();
        $this->assertNotEmpty($cmd->desc());
        $help = (string) $cmd->help([]);
        foreach (['--no-scan', '--dry-run', '--dir', 'config/trader.php', 'strategies'] as $k) {
            $this->assertStringContainsString($k, $help);
        }
    }

    public function testDryRunReportsPlanWithoutWriting(): void
    {
        // 记录当前 WmcStrategy 是否存在，dry-run 后状态必须不变
        $before = Strategy::query()->where('alias', 'WmcStrategy')->exists();

        $argv = ['php', 'sikelan', 'trader:sync-strategies', '--dry-run'];
        CommandManager::getInstance()->setOriginArgv($argv);
        $out = (string) (new TraderSyncStrategiesCommand())->exec([]);

        $this->assertStringContainsString('未落库', $out);
        // config/trader.php 中注册的 WmcStrategy 必须出现在计划里
        $this->assertStringContainsString('WmcStrategy', $out);

        $after = Strategy::query()->where('alias', 'WmcStrategy')->exists();
        $this->assertSame($before, $after, 'dry-run 不应改变 strategies 表数据');
    }
}
