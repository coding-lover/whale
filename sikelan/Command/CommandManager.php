<?php

namespace Sikelan\Command;

class CommandManager
{
    private static ?CommandManager $instance = null;

    private array $commands = [];

    private array $originArgv = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function addCommand(CommandInterface $command): self
    {
        $this->commands[$command->commandName()] = $command;
        return $this;
    }

    public function getCommand(string $name): ?CommandInterface
    {
        return $this->commands[$name] ?? null;
    }

    public function hasCommand(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    public function getCommands(): array
    {
        return $this->commands;
    }

    public function setOriginArgv(array $argv): self
    {
        $this->originArgv = $argv;
        return $this;
    }

    public function getOriginArgv(): array
    {
        return $this->originArgv;
    }

    public function getOpt(string $key, $default = null)
    {
        $argv = $this->originArgv;
        foreach ($argv as $arg) {
            if (strpos($arg, "{$key}=") === 0) {
                return substr($arg, strlen($key) + 1);
            }
            if ($arg === $key) {
                return true;
            }
        }
        return $default;
    }

    public function getArgs(): array
    {
        $argv = $this->originArgv;
        $args = [];
        foreach ($argv as $i => $arg) {
            if ($i === 0) {
                continue;
            }
            if (strpos($arg, '-') === 0) {
                continue;
            }
            $args[] = $arg;
        }
        return $args;
    }
}
