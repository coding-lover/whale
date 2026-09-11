<?php

namespace Sikelan\Core;

use Symfony\Component\Yaml\Yaml;

class Config
{
    protected $config = [];
    protected $configPath;
    protected $environment;

    public function __construct(string $configPath = '', string $environment = '')
    {
        $this->configPath = $configPath ?: CONFIG_PATH;
        $this->environment = $environment;
        $this->loadConfig();
    }

    protected function loadConfig()
    {
        $this->loadFromPath($this->configPath);

        if ($this->environment) {
            $envPath = $this->configPath . '/' . $this->environment;
            if (is_dir($envPath)) {
                $this->loadFromPath($envPath, true);
            }
        }
    }

    protected function loadFromPath(string $path, bool $merge = false)
    {
        $files = glob($path . '/*.php');

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $data = require $file;

            if ($merge && isset($this->config[$name])) {
                $this->config[$name] = $this->mergeConfig($this->config[$name], $data);
            } else {
                $this->config[$name] = $data;
            }
        }

        $yamlFiles = glob($path . '/*.yaml');
        foreach ($yamlFiles as $file) {
            $name = basename($file, '.yaml');
            $data = Yaml::parseFile($file);

            if ($merge && isset($this->config[$name])) {
                $this->config[$name] = $this->mergeConfig($this->config[$name], $data);
            } else {
                $this->config[$name] = $data;
            }
        }

        $jsonFiles = glob($path . '/*.json');
        foreach ($jsonFiles as $file) {
            $name = basename($file, '.json');
            $data = json_decode(file_get_contents($file), true);

            if ($merge && isset($this->config[$name])) {
                $this->config[$name] = $this->mergeConfig($this->config[$name], $data);
            } else {
                $this->config[$name] = $data;
            }
        }
    }

    protected function mergeConfig(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeConfig($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function set(string $key, $value)
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $config[$k] = $value;
            } else {
                if (!isset($config[$k])) {
                    $config[$k] = [];
                }
                $config = &$config[$k];
            }
        }

        return $this;
    }

    public function all()
    {
        return $this->config;
    }
}
