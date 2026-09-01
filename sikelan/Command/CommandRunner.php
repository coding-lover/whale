<?php

namespace Sikelan\Command;

class CommandRunner
{
    private static ?CommandRunner $instance = null;

    private CommandManager $commandManager;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->commandManager = CommandManager::getInstance();
        $this->registerDefaultCommands();
        $this->registerAppCommands();
    }

    private function registerDefaultCommands(): void
    {
        $defaultCommands = [
            new DefaultCommand\ServerCommand(),
            new DefaultCommand\HelpCommand(),
            new DefaultCommand\MakeControllerCommand(),
            new DefaultCommand\MakeModelCommand(),
            new DefaultCommand\MakeTaskCommand(),
            new DefaultCommand\ConfigCommand(),
            new DefaultCommand\RouteCommand(),
        ];

        foreach ($defaultCommands as $command) {
            $this->commandManager->addCommand($command);
        }
    }

    /**
     * 自动注册应用层命令（App\Commands\*，全部放在 app/Commands/*.php，实现 CommandInterface）。
     *
     * 设计目的（开闭）：业务团队要加新的 CLI 命令，只需要在 app/Commands/ 下建文件，
     * 不用改 sikelan/ 的 CommandRunner 代码。
     *
     * 规则：
     *   - 目录：APP_PATH . '/Commands'（缺省则跳过，不报错）
     *   - 命名空间：App\Commands\*（必须和 PSR-4 对齐）
     *   - 类型约束：implements CommandInterface；否则会 skip 并警告（debug 模式下）
     *   - 重名冲突：若应用层命令与 sikelan 默认命令同名（不推荐），应用层会覆盖默认，
     *     这样开发者可以自行扩展。
     */
    private function registerAppCommands(): void
    {
        // 入口里如果没走 constants.php，也要兼容（某些极简脚本可能直接 new CommandRunner）
        $appPath = defined('APP_PATH') ? APP_PATH : (dirname(__DIR__, 2) . '/app');
        $dir = $appPath . '/Commands';
        if (!is_dir($dir)) {
            return;
        }

        // 只遍历顶层 .php 文件（不递归，避免子目录混入非命令类）
        $files = glob($dir . '/*.php');
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            $className = 'App\\Commands\\' . basename($file, '.php');
            if (!class_exists($className, true)) {
                // 类不存在：可能文件内容没正确命名空间 → 给 warning，但不 fatal 让其他命令可用
                trigger_error(
                    "命令文件未找到对应类 {$className}（路径 {$file}）。请检查 PSR-4 命名空间 App\\Commands 是否正确。",
                    E_USER_WARNING
                );
                continue;
            }
            if (!is_a($className, CommandInterface::class, true)) {
                trigger_error(
                    "{$className} 未实现 " . CommandInterface::class . '，跳过注册为 CLI 命令。',
                    E_USER_WARNING
                );
                continue;
            }
            /** @var CommandInterface $instance */
            $instance = new $className();
            $this->commandManager->addCommand($instance);
        }
    }

    public function run(array $argv): int
    {
        $this->commandManager->setOriginArgv($argv);

        if (count($argv) < 2) {
            $this->showLogo();
            $this->showHelp();
            return 0;
        }

        $commandName = $argv[1];

        if ($commandName === 'help' || $commandName === '--help' || $commandName === '-h') {
            $this->showLogo();

            // 支持 help <command> 查看单个命令的帮助
            if (isset($argv[2]) && strpos($argv[2], '-') !== 0) {
                $targetCommand = $this->commandManager->getCommand($argv[2]);
                if ($targetCommand !== null) {
                    $this->showCommandHelp($targetCommand);
                    return 0;
                }
                echo "\033[31mError: Command '{$argv[2]}' not found.\033[0m\n";
                return 1;
            }

            $this->showHelp();
            return 0;
        }

        if ($commandName === 'list') {
            $this->showLogo();
            $this->showCommandList();
            return 0;
        }

        $command = $this->commandManager->getCommand($commandName);

        if ($command === null) {
            echo "\033[31mError: Command '{$commandName}' not found.\033[0m\n";
            echo "Run 'php sikelan list' to see available commands.\n";
            return 1;
        }

        if (isset($argv[2]) && ($argv[2] === '--help' || $argv[2] === '-h')) {
            $this->showCommandHelp($command);
            return 0;
        }

        $args = array_slice($argv, 2);
        $result = $command->exec($args);

        if ($result !== null) {
            echo $result . "\n";
        }

        return 0;
    }

    private function showLogo(): void
    {
        $logo = <<<'LOGO'
 ____ ___ _  _______ _        _    _   _ 
/ ___|_ _| |/ / ____| |      / \  | \ | |
\___ \| || ' /|  _| | |     / _ \ |  \| |
 ___) | || . \| |___| |___ / ___ \| |\  |
|____/___|_|\_\_____|_____/_/   \_\_| \_|
                                        
LOGO;
        echo $logo;
        echo "\033[36mSikelan Framework v1.0.0\033[0m\n\n";
    }

    private function showHelp(): void
    {
        echo "Usage:\n";
        echo "  php sikelan <command> [options]\n\n";
        echo "Available Commands:\n";

        $commands = $this->commandManager->getCommands();
        ksort($commands);

        foreach ($commands as $name => $command) {
            $desc = $command->desc();
            printf("  \033[32m%-24s\033[0m %s\n", $name, $desc);
        }

        echo "\n";
        echo "Options:\n";
        echo "  -h, --help     Show help\n";
        echo "  list           List all commands\n";
        echo "  help <cmd>     Show command help\n\n";
        echo "Run 'php sikelan help <command>' for more information on a specific command.\n";
    }

    private function showCommandList(): void
    {
        echo "All Registered Commands:\n\n";

        $commands = $this->commandManager->getCommands();
        ksort($commands);

        foreach ($commands as $name => $command) {
            echo "\033[32m{$name}\033[0m\n";
            echo "  {$command->desc()}\n\n";
        }
    }

    private function showCommandHelp(CommandInterface $command): void
    {
        echo "\033[32m{$command->commandName()}\033[0m - {$command->desc()}\n\n";
        $help = $command->help([]);
        if ($help !== null) {
            echo $help . "\n";
        }
    }

    public function getCommandManager(): CommandManager
    {
        return $this->commandManager;
    }

    /**
     * 注册自定义命令
     *
     * @param CommandInterface $command 命令实例
     * @return self
     */
    public function addCommand(CommandInterface $command): self
    {
        $this->commandManager->addCommand($command);
        return $this;
    }

    /**
     * 通过配置文件加载命令
     *
     * @param string $configFile 命令配置文件路径
     * @return self
     */
    public function loadCommandsFromConfig(string $configFile): self
    {
        if (!file_exists($configFile)) {
            return $this;
        }

        $commands = require $configFile;
        if (!is_array($commands)) {
            return $this;
        }

        foreach ($commands as $commandClass) {
            if (is_string($commandClass) && class_exists($commandClass)) {
                $command = new $commandClass();
                if ($command instanceof CommandInterface) {
                    $this->commandManager->addCommand($command);
                }
            } elseif ($commandClass instanceof CommandInterface) {
                $this->commandManager->addCommand($commandClass);
            }
        }

        return $this;
    }
}
