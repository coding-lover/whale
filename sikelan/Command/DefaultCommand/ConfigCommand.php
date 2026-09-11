<?php

namespace Sikelan\Command\DefaultCommand;

use Sikelan\Command\CommandInterface;
use Sikelan\Framework;

class ConfigCommand implements CommandInterface
{
    public function commandName(): string
    {
        return 'config';
    }

    public function exec(array $args): ?string
    {
        $action = $args[0] ?? 'show';

        switch ($action) {
            case 'show':
                return $this->show($args);
            case 'get':
                return $this->get($args);
            case 'set':
                return $this->set($args);
            case 'clear':
                return $this->clear($args);
            default:
                return "\033[31mUnknown action: {$action}\033[0m\n" . $this->help([]);
        }
    }

    public function help(array $args): ?string
    {
        return <<<HELP
Config Management Command

Usage:
  php sikelan config show [key]     Show all config or specific key
  php sikelan config get <key>      Get a config value
  php sikelan config set <key> <value>  Set a config value (runtime only)
  php sikelan config clear          Clear cache

Arguments:
  key      Config key (supports dot notation: app.debug, database.default.host)
  value    Value to set

Examples:
  php sikelan config show
  php sikelan config show app
  php sikelan config get app.name
  php sikelan config set app.debug true
  php sikelan config clear
HELP;
    }

    public function desc(): string
    {
        return 'View and manage configuration';
    }

    private function show(array $args): string
    {
        $app = Framework::getInstance();
        $config = $app->getConfig();
        $key = $args[1] ?? null;

        if ($key === null) {
            $data = $this->flattenConfig($config->all());
            echo "All Configuration:\n";
            echo str_repeat('-', 60) . "\n";

            foreach ($data as $configKey => $value) {
                $displayValue = $this->formatDisplayValue($value);
                printf("  \033[33m%-40s\033[0m %s\n", $configKey, $displayValue);
            }
            return '';
        }

        $value = $config->get($key);
        if ($value === null) {
            return "\033[33mConfig key '{$key}' not found.\033[0m";
        }

        if (is_array($value)) {
            echo json_encode($value, JSON_PRETTY_PRINT) . "\n";
        } elseif (is_object($value)) {
            echo "{$key}: [Object: " . get_class($value) . "]\n";
        } else {
            echo "{$key}: {$value}\n";
        }
        return '';
    }

    /**
     * 格式化显示值，处理 Closure、对象、资源等非标量类型
     */
    private function formatDisplayValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_string($value) || is_numeric($value)) {
            return (string)$value;
        }

        if ($value instanceof \Closure) {
            return '[Closure]';
        }

        if (is_object($value)) {
            return '[Object: ' . get_class($value) . ']';
        }

        if (is_array($value)) {
            return '[Array: ' . count($value) . ' items]';
        }

        if (is_resource($value)) {
            return '[Resource]';
        }

        return (string)$value;
    }

    private function get(array $args): string
    {
        $app = Framework::getInstance();
        $config = $app->getConfig();
        $key = $args[1] ?? '';

        if (empty($key)) {
            return "\033[31mError: Config key is required.\033[0m";
        }

        $value = $config->get($key);
        if ($value === null) {
            return "\033[33mConfig key '{$key}' not found.\033[0m";
        }

        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT);
        }

        return $this->formatDisplayValue($value);
    }

    private function set(array $args): string
    {
        $app = Framework::getInstance();
        $config = $app->getConfig();
        $key = $args[1] ?? '';
        $value = $args[2] ?? '';

        if (empty($key)) {
            return "\033[31mError: Config key is required.\033[0m";
        }

        $config->set($key, $value);
        return "\033[32mConfig '{$key}' set to '{$value}' (runtime only).\033[0m";
    }

    private function clear(array $args): string
    {
        $cachePath = RUNTIME_PATH . '/cache/config.php';
        if (file_exists($cachePath)) {
            @unlink($cachePath);
            return "\033[32mConfig cache cleared.\033[0m";
        }
        return "\033[33mNo config cache found.\033[0m";
    }

    private function flattenConfig(array $config, string $prefix = ''): array
    {
        $result = [];
        foreach ($config as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                $result = array_merge($result, $this->flattenConfig($value, $fullKey));
            } elseif (is_object($value)) {
                $result[$fullKey] = $value;
            } else {
                $result[$fullKey] = $value;
            }
        }
        return $result;
    }
}
