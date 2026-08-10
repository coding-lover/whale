<?php

namespace Sikelan\Command\DefaultCommand;

use Sikelan\Command\CommandInterface;
use Sikelan\Command\CommandManager;

class HelpCommand implements CommandInterface
{
    public function commandName(): string
    {
        return 'help';
    }

    public function exec(array $args): ?string
    {
        if (empty($args)) {
            $commands = CommandManager::getInstance()->getCommands();
            echo "Available Commands:\n";
            echo str_repeat('-', 60) . "\n";

            ksort($commands);
            foreach ($commands as $name => $command) {
                printf("  \033[32m%-24s\033[0m %s\n", $name, $command->desc());
            }

            echo "\nUsage:\n";
            echo "  php sikelan help <command>\n";
            echo "  php sikelan <command> --help\n";
            return '';
        }

        $commandName = $args[0];
        $command = CommandManager::getInstance()->getCommand($commandName);

        if ($command === null) {
            return "\033[31mError: Command '{$commandName}' not found.\033[0m";
        }

        $help = $command->help($args);
        if ($help !== null) {
            return $help;
        }

        return "\033[32m{$commandName}\033[0m - {$command->desc()}";
    }

    public function help(array $args): ?string
    {
        return "Usage: php sikelan help [command]\n\nShow help for a specific command.";
    }

    public function desc(): string
    {
        return 'Show help for commands';
    }
}
