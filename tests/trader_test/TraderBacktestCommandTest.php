<?php

namespace Sikelan\Tests\trader_test;

use App\Commands\TraderBacktestCommand;
use PHPUnit\Framework\TestCase;
use Sikelan\Command\CommandInterface;
use Sikelan\Command\CommandManager;
use Sikelan\Command\CommandRunner;

/**
 * trader:backtest 命令测试。
 *
 * 覆盖：
 *   1. 命令自动注册 + desc/help
 *   2. 参数解析（交易对逗号分隔/去重、默认值、校验错误）
 *   3. 时间窗口解析（--days / --from/--to / 互斥 / 日期边界）
 *   4. exec 集成：--list-strategies / --dry-run（真实 CSV）/ 错误参数不抛异常 / JSON 回测（需 trader 扩展）
 */
class TraderBacktestCommandTest extends TestCase
{
    private TraderBacktestCommand $cmd;

    protected function setUp(): void
    {
        $this->cmd = new TraderBacktestCommand();
    }

    protected function tearDown(): void
    {
        // CommandManager / CommandRunner 都是单例，测试间必须重置避免 argv 污染
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

    /** 用给定 argv 执行命令（argv[0]=php, argv[1]=命令名, 其余为选项） */
    private function execWith(array $options): string
    {
        $argv = array_merge(['php', 'sikelan', 'trader:backtest'], $options);
        CommandManager::getInstance()->setOriginArgv($argv);
        return (string) (new TraderBacktestCommand())->exec([]);
    }

    /** 反射调用 protected 方法 */
    private function callProtected(string $method, array $args)
    {
        $m = new \ReflectionMethod(TraderBacktestCommand::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->cmd, $args);
    }

    // ====================================================================
    //  1. 自动注册 + 元信息
    // ====================================================================

    public function testCommandIsAutoRegistered(): void
    {
        CommandRunner::getInstance();
        $manager = CommandManager::getInstance();

        $this->assertTrue($manager->hasCommand('trader:backtest'));
        $registered = $manager->getCommand('trader:backtest');
        $this->assertInstanceOf(CommandInterface::class, $registered);
        $this->assertInstanceOf(TraderBacktestCommand::class, $registered);
    }

    public function testDescAndHelpAreMeaningful(): void
    {
        $this->assertNotEmpty($this->cmd->desc());
        $help = (string) $this->cmd->help([]);
        foreach (['--exchange', '--symbol', '--timeframe', '--strategy', '--days',
                  '--from', '--to', '--capital', '--warmup', '--no-download',
                  '--json', '--dry-run', '--list-strategies'] as $opt) {
            $this->assertStringContainsString($opt, $help, "help 必须包含选项 {$opt}");
        }
    }

    // ====================================================================
    //  2. 参数解析
    // ====================================================================

    public function testParseSymbolsSplitsTrimsAndDedupes(): void
    {
        $symbols = $this->callProtected('parseSymbols', [' BTC/USDT ,ETH/USDT,, BTC/USDT , ']);
        $this->assertSame(['BTC/USDT', 'ETH/USDT'], $symbols);
    }

    public function testParsePlanDefaults(): void
    {
        $raw = $this->rawOptions([]);
        [$plan, $error] = $this->callProtected('parsePlan', [$raw]);

        $this->assertNull($error);
        $this->assertSame('binance', $plan['exchange']);
        $this->assertSame(['BTC/USDT'], $plan['symbols']);
        $this->assertSame('1h', $plan['timeframe']);
        $this->assertSame('MeanRevStd', $plan['strategy']);
        $this->assertSame(10000.0, $plan['capital']);
        $this->assertSame(60, $plan['warmup']);
        $this->assertTrue($plan['auto_download']);
        $this->assertNull($plan['from_ms']);
        $this->assertNull($plan['to_ms']);
        // 未指定窗口时，下载参数默认近 7 天
        $this->assertSame(['days' => 7], $plan['download_opts']);
    }

    /** @dataProvider invalidPlanProvider */
    public function testParsePlanValidationErrors(array $override, string $expectedFragment): void
    {
        $raw = $this->rawOptions($override);
        [$plan, $error] = $this->callProtected('parsePlan', [$raw]);

        $this->assertSame([], $plan);
        $this->assertNotNull($error);
        $this->assertStringContainsString($expectedFragment, $error);
    }

    public function invalidPlanProvider(): array
    {
        return [
            '非法周期'        => [['timeframe' => '99x'], '不支持的周期'],
            '空交易对'        => [['symbol' => ' , '], '至少一个交易对'],
            '资金非正'        => [['capital' => '0'], '--capital 必须是正数'],
            '资金非数'        => [['capital' => 'abc'], '--capital 必须是正数'],
            'warmup 非整数'   => [['warmup' => '1.5'], '--warmup 必须是非负整数'],
            'days 与 from 冲突' => [['days' => '7', 'from' => '2026-01-01'], '不能同时使用'],
            'days 非正整数'    => [['days' => '0'], '--days 必须是正整数'],
            'from 日期格式错'  => [['from' => '2026/01/01'], 'YYYY-MM-DD'],
            'from 晚于 to'    => [['from' => '2026-03-01', 'to' => '2026-01-01'], '不能晚于'],
            '空策略'          => [['strategy' => ''], '--strategy'],
        ];
    }

    public function testParsePlanDaysWindow(): void
    {
        $raw = $this->rawOptions(['days' => '30', 'symbol' => 'BTC/USDT,ETH/USDT']);
        [$plan, $error] = $this->callProtected('parsePlan', [$raw]);

        $this->assertNull($error);
        $this->assertSame(['BTC/USDT', 'ETH/USDT'], $plan['symbols']);
        $this->assertSame(['days' => 30], $plan['download_opts']);
        // from_ms = 今天 0 点（UTC）往前 30 个自然日；to_ms 不截断
        $expectedStart = (int) floor(time() / 86400) * 86400 - 30 * 86400;
        $this->assertSame($expectedStart * 1000, $plan['from_ms']);
        $this->assertNull($plan['to_ms']);
    }

    public function testParsePlanFromToWindow(): void
    {
        $raw = $this->rawOptions(['from' => '2026-01-01', 'to' => '2026-03-31', 'no_download' => false]);
        [$plan, $error] = $this->callProtected('parsePlan', [$raw]);

        $this->assertNull($error);
        // 起点 = 2026-01-01 00:00:00 UTC
        $this->assertSame(strtotime('2026-01-01 UTC') * 1000, $plan['from_ms']);
        // 终点 = 2026-03-31 23:59:59.999 UTC（含当天最后一根 K 线）
        $this->assertSame((strtotime('2026-03-31 UTC') + 86399) * 1000 + 999, $plan['to_ms']);
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-03-31'], $plan['download_opts']);
    }

    public function testParsePlanNoDownloadFlag(): void
    {
        $raw = $this->rawOptions(['no_download' => true]);
        [$plan, $error] = $this->callProtected('parsePlan', [$raw]);

        $this->assertNull($error);
        $this->assertFalse($plan['auto_download']);
        $this->assertTrue($plan['allow_gaps'] === false);
    }

    public function testParseDateBoundaryStartAndEndOfDay(): void
    {
        $start = $this->callProtected('parseDateBoundary', ['2026-01-15', false]);
        $end   = $this->callProtected('parseDateBoundary', ['2026-01-15', true]);

        $this->assertSame(strtotime('2026-01-15 UTC') * 1000, $start);
        $this->assertSame((strtotime('2026-01-15 UTC') + 86399) * 1000 + 999, $end);
        // 非法日期
        $this->assertNull($this->callProtected('parseDateBoundary', ['15-01-2026', false]));
        $this->assertNull($this->callProtected('parseDateBoundary', ['not-a-date', false]));
    }

    // ====================================================================
    //  3. exec 集成
    // ====================================================================

    public function testExecListStrategiesShowsRegisteredAliases(): void
    {
        $out = $this->execWith(['--list-strategies']);
        $this->assertStringContainsString('MeanRevStd', $out);
        $this->assertStringContainsString('EmaCross20_50', $out);
        $this->assertStringContainsString('BollingerRsiMeanReversionStrategy', $out);
    }

    public function testExecDryRunWithRealCsv(): void
    {
        $csv = RUNTIME_PATH . '/trader/data/binance/BTC-USDT_1h.csv';
        if (!is_file($csv)) {
            $this->markTestSkipped("真实 CSV 不存在：{$csv}");
        }
        // --no-download 保证不触网；dry-run 不依赖 trader 扩展
        $out = $this->execWith(['--dry-run', '--no-download', '--symbol=BTC/USDT', '--timeframe=1h']);

        $this->assertStringContainsString('DRY-RUN', $out);
        $this->assertStringContainsString('BTC/USDT', $out);
        $this->assertStringContainsString('1h', $out);
        $this->assertStringContainsString('168 根', $out, 'dry-run 应展示每个 pair 的 K 线数');
    }

    public function testExecInvalidParamsReturnsErrorNotThrow(): void
    {
        // 错误参数必须返回 [ERROR] 字符串而不是抛异常（CommandRunner 只 echo 返回值）
        $out = $this->execWith(['--timeframe=99x']);
        $this->assertStringContainsString('[ERROR]', $out);
        $this->assertStringContainsString('不支持的周期', $out);
    }

    public function testExecNoDownloadMissingCsvGivesHint(): void
    {
        // 不存在的 pair + --no-download → 报错且包含手动下载指引
        $out = $this->execWith(['--no-download', '--symbol=THIS_PAIR_NEVER_EXISTS/XYZ', '--timeframe=1h']);
        $this->assertStringContainsString('[ERROR]', $out);
        $this->assertStringContainsString('trader:download-klines', $out, '错误消息应给出手动下载命令指引');
    }

    public function testExecRealBacktestJsonOutput(): void
    {
        if (!extension_loaded('trader')) {
            $this->markTestSkipped('缺少 trader 扩展，跳过端到端回测测试。');
        }
        $csv = RUNTIME_PATH . '/trader/data/binance/BTC-USDT_1h.csv';
        if (!is_file($csv)) {
            $this->markTestSkipped("真实 CSV 不存在：{$csv}");
        }

        $out = $this->execWith([
            '--no-download', '--json', '--warmup=60',
            '--symbol=BTC/USDT', '--timeframe=1h',
        ]);

        $payload = json_decode($out, true);
        $this->assertIsArray($payload, 'JSON 输出必须可解析：' . substr($out, 0, 200));
        $this->assertSame('binance', $payload['plan']['exchange']);
        $this->assertSame(['BTC/USDT'], $payload['plan']['symbols']);
        $this->assertArrayHasKey('total_return_pct', $payload['metrics']);
        $this->assertArrayHasKey('sharpe_ratio', $payload['metrics']);
        $this->assertSame('1h', $payload['metrics']['timeframe']);
    }

    // ====================================================================
    //  helpers
    // ====================================================================

    /**
     * 构造 readRawOptions() 产出形态的原始参数数组（string/bool），
     * $override 里给的键覆盖默认值。
     */
    private function rawOptions(array $override): array
    {
        $defaults = [
            'exchange'        => 'binance',
            'symbol'          => 'BTC/USDT',
            'timeframe'       => '1h',
            'strategy'        => 'MeanRevStd',
            'days'            => '',
            'from'            => '',
            'to'              => '',
            'capital'         => '10000',
            'warmup'          => '60',
            'no_download'     => false,
            'allow_gaps'      => false,
            'list_strategies' => false,
            'json'            => false,
            'dry_run'         => false,
        ];
        return array_merge($defaults, $override);
    }
}
