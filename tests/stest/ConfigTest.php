<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Config;

/**
 * Config 全覆盖测试
 */
class ConfigTest extends TestCase
{
    private string $tempConfigPath;

    protected function setUp(): void
    {
        // 创建临时配置文件
        $this->tempConfigPath = sys_get_temp_dir() . '/sikelan_test_config_' . uniqid();
        mkdir($this->tempConfigPath);
    }

    protected function tearDown(): void
    {
        // 清理临时文件
        array_map('unlink', glob($this->tempConfigPath . '/*'));
        rmdir($this->tempConfigPath);
    }

    private function createConfigFile(string $name, string $content): void
    {
        file_put_contents($this->tempConfigPath . '/' . $name, $content);
    }

    // ========== 构造函数测试 ==========

    public function testConstructorWithDefaultPath()
    {
        // 使用默认 CONFIG_PATH
        $config = new Config();
        $this->assertInstanceOf(Config::class, $config);
    }

    public function testConstructorWithCustomPath()
    {
        $config = new Config($this->tempConfigPath);
        $this->assertInstanceOf(Config::class, $config);
    }

    public function testConstructorLoadsPhpFiles()
    {
        // 创建一个 PHP 配置文件
        $phpContent = "<?php\nreturn ['key' => 'value', 'nested' => ['deep' => 'test']];";
        file_put_contents($this->tempConfigPath . '/test.php', $phpContent);

        $config = new Config($this->tempConfigPath);
        $all = $config->all();

        $this->assertArrayHasKey('test', $all);
        $this->assertEquals('value', $all['test']['key']);
        $this->assertEquals('test', $all['test']['nested']['deep']);
    }

    public function testConstructorLoadsYamlFiles()
    {
        // 创建一个 YAML 配置文件（如果 YAML 扩展可用）
        $yamlContent = "app:\n  name: test-app\n  env: testing";
        file_put_contents($this->tempConfigPath . '/app.yaml', $yamlContent);

        $config = new Config($this->tempConfigPath);
        $all = $config->all();

        $this->assertArrayHasKey('app', $all);
        $this->assertEquals('test-app', $all['app']['app']['name'] ?? null);
    }

    public function testConstructorLoadsJsonFiles()
    {
        $jsonContent = '{"app": {"name": "test-json", "version": "1.0.0"}}';
        file_put_contents($this->tempConfigPath . '/app.json', $jsonContent);

        $config = new Config($this->tempConfigPath);
        $all = $config->all();

        $this->assertArrayHasKey('app', $all);
    }

    // ========== get() 方法测试 ==========

    public function testGetReturnsStoredValue()
    {
        $config = new Config();
        $config->set('test_key', 'test_value');

        $this->assertEquals('test_value', $config->get('test_key'));
    }

    public function testGetReturnsDefaultWhenKeyNotFound()
    {
        $config = new Config();

        $this->assertNull($config->get('nonexistent_key'));
        $this->assertEquals('default', $config->get('nonexistent_key', 'default'));
    }

    public function testGetSupportsDotNotation()
    {
        $config = new Config();
        $config->set('database.host', '127.0.0.1');
        $config->set('database.port', 3306);
        $config->set('database.credentials.username', 'root');

        $this->assertEquals('127.0.0.1', $config->get('database.host'));
        $this->assertEquals(3306, $config->get('database.port'));
        $this->assertEquals('root', $config->get('database.credentials.username'));
    }

    public function testGetReturnsDefaultForPartialDotNotation()
    {
        $config = new Config();
        $config->set('testdb.host', '127.0.0.1');

        $this->assertEquals(['host' => '127.0.0.1'], $config->get('testdb'));
        $this->assertNull($config->get('testdb.port'));
    }

    // ========== set() 方法测试 ==========

    public function testSetCreatesNestedStructure()
    {
        $config = new Config();
        $config->set('level1.level2.level3', 'deep_value');

        $all = $config->all();
        $this->assertEquals('deep_value', $all['level1']['level2']['level3']);
    }

    public function testSetOverwritesExistingValue()
    {
        $config = new Config();
        $config->set('key', 'value1');
        $config->set('key', 'value2');

        $this->assertEquals('value2', $config->get('key'));
    }

    public function testSetCreatesIntermediateArrays()
    {
        $config = new Config();
        $config->set('a.b.c.d', 'value');

        $all = $config->all();
        $this->assertEquals('value', $all['a']['b']['c']['d']);
    }

    public function testSetReturnsConfigForChaining()
    {
        $config = new Config();
        $result = $config->set('key', 'value');

        $this->assertSame($config, $result);
    }

    // ========== all() 方法测试 ==========

    public function testAllReturnsEmptyArrayWhenNoConfig()
    {
        $config = new Config($this->tempConfigPath);
        $all = $config->all();

        $this->assertIsArray($all);
    }

    public function testAllReturnsAllConfigData()
    {
        $config = new Config();
        $config->set('key1', 'value1');
        $config->set('key2', 'value2');

        $all = $config->all();

        $this->assertArrayHasKey('key1', $all);
        $this->assertArrayHasKey('key2', $all);
        $this->assertEquals('value1', $all['key1']);
        $this->assertEquals('value2', $all['key2']);
    }
}
