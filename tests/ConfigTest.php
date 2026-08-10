<?php

namespace Sikelan\Tests;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Config;

class ConfigTest extends TestCase
{
    public function testConfigGet()
    {
        $config = new Config(__DIR__ . '/../config');

        $this->assertEquals('Sikelan', $config->get('app.name'));
        $this->assertEquals('development', $config->get('app.env'));
    }

    public function testConfigSet()
    {
        $config = new Config();
        $config->set('custom.key', 'custom_value');

        $this->assertEquals('custom_value', $config->get('custom.key'));
    }

    public function testConfigDefault()
    {
        $config = new Config();

        $this->assertEquals('default', $config->get('non.exist.key', 'default'));
        $this->assertNull($config->get('non.exist.key'));
    }

    public function testConfigAll()
    {
        $config = new Config();

        $this->assertIsArray($config->all());
    }
}
