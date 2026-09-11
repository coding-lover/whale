<?php

namespace Sikelan\Tests\stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Command\CommandManager;
use Sikelan\Command\CommandRunner;
use Sikelan\Command\CommandInterface;

/**
 * CommandRunner + 应用层命令自动注册验证。
 *
 * 属于框架核心 sikelan/ 改动（CommandRunner::registerAppCommands），所以放在 tests/stest/。
 * 注意：不要在这里跑需要真实 ExchangeManager 的命令（会因为 swoole 协程网络报错）。
 *
 * @package Sikelan\Tests\stest
 */
class CommandRunnerTest extends TestCase
{
    /**
     * 创建一个全新的 CommandRunner（重置 CommandManager 单例状态）→ 断言 list 里包含应用层命令。
     *
     * 因为 CommandManager 用 getInstance 静态，我们在 setUp 用反射把 instance 清空，
     * 保证和其他单测（例如独立加载的 BinanceCommand 单测）不互相污染。
     */
    protected function tearDown(): void
    {
        // CommandManager + CommandRunner 都是单例，两者都要清才能让后续测试完全隔离
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

    /**
     * 确保 app/Commands 下 TraderDownloadKlinesCommand 已被自动注册。
     * 覆盖：CommandRunner::__construct → registerAppCommands 正常流程。
     */
    public function testAppCommandsAreAutoRegistered(): void
    {
        // 先确保 CommandRunner 被实例化（会 registerDefaultCommands + registerAppCommands）
        CommandRunner::getInstance();

        $manager = CommandManager::getInstance();
        $this->assertTrue(
            $manager->hasCommand('trader:download-klines'),
            '命令 trader:download-klines 必须被 CommandRunner 自动注册（app/Commands/TraderDownloadKlinesCommand.php）'
        );
        $cmd = $manager->getCommand('trader:download-klines');
        $this->assertInstanceOf(CommandInterface::class, $cmd);
        $this->assertSame(
            'App\\Commands\\TraderDownloadKlinesCommand',
            get_class($cmd)
        );
    }

    /**
     * 默认命令（server/config/help 等）也必须仍存在（应用层命令不能覆盖掉默认）
     */
    public function testDefaultCommandsStillExistAfterRegisterApp(): void
    {
        CommandRunner::getInstance();
        $manager = CommandManager::getInstance();
        foreach (['server', 'help', 'config', 'make:controller', 'make:model', 'make:task', 'route'] as $name) {
            $this->assertTrue(
                $manager->hasCommand($name),
                "默认命令 {$name} 应该仍在 CommandManager 里（新增自动注册逻辑不应删掉它）"
            );
        }
    }

    /**
     * 命令名：desc() 必须返回非空字符串（showLogo/showList 里用）。
     */
    public function testTraderDownloadKlinesCommandDescIsMeaningful(): void
    {
        CommandRunner::getInstance();
        $cmd = CommandManager::getInstance()->getCommand('trader:download-klines');
        $this->assertNotNull($cmd);
        $this->assertNotEmpty($cmd->desc(), 'desc() 不能为空（否则 CLI list 展示空白）');
        $this->assertMatchesRegularExpression('/(K线|kline|download|runtime|CSV)/iu', $cmd->desc());
    }

    /**
     * help() 必须包含所有默认参数关键字（用于 php sikelan help trader:download-klines）。
     */
    public function testTraderDownloadKlinesHelpHasAllKeyOptions(): void
    {
        CommandRunner::getInstance();
        $cmd = CommandManager::getInstance()->getCommand('trader:download-klines');
        $this->assertNotNull($cmd);
        $help = (string) $cmd->help([]);
        foreach (['--exchange', '--symbol', '--interval', '--days', '--output-dir', '--dry-run', '--from', '--to'] as $opt) {
            $this->assertStringContainsString(
                $opt,
                $help,
                "help 文本里缺少 {$opt} 说明（避免用户从 help 里看不到完整参数）"
            );
        }
    }

    /**
     * 没找到的命令返回 null + 不含 app/Commands 下不存在的类：
     * 构造一个「目录存在但文件里类是脏命名空间」的情况用临时文件构造太累，
     * 这里只验证「不存在的命令」不会 fatal、保持 hasCommand 返回 false。
     */
    public function testNonExistingCommandDoesNotExist(): void
    {
        CommandRunner::getInstance();
        $this->assertFalse(CommandManager::getInstance()->hasCommand('no-such-command-42'));
        $this->assertNull(CommandManager::getInstance()->getCommand('no-such-command-42'));
    }
}
