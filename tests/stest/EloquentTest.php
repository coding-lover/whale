<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Config;
use Sikelan\Database\EloquentManager;
use Sikelan\Database\PdoPool;
use App\Models\Model;

/**
 * Eloquent ORM 集成测试
 *
 * 验证 PdoPool 配置读取、EloquentManager 初始化、Model 基类结构。
 * 不依赖真实 MySQL 连接（PdoPool 懒加载，boot() 不触发连接）。
 */
class EloquentTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config();
        $this->config->set('database.mysql', [
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'root',
            'password' => 'secret',
            'database' => 'sikelan_test',
            'charset' => 'utf8mb4',
            'timeout' => 5,
            'pool_size' => 10,
        ]);
    }

    public function testPdoPoolReadsConfig()
    {
        $pool = new PdoPool($this->config);

        $reflection = new \ReflectionClass($pool);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $config = $configProp->getValue($pool);

        $this->assertEquals('127.0.0.1', $config['host']);
        $this->assertEquals(3306, $config['port']);
        $this->assertEquals('root', $config['username']);
        $this->assertEquals('secret', $config['password']);
        $this->assertEquals('sikelan_test', $config['database']);
        $this->assertEquals('utf8mb4', $config['charset']);
    }

    public function testPdoPoolMaxConnectionsFromConfig()
    {
        $pool = new PdoPool($this->config);

        $reflection = new \ReflectionClass($pool);
        $maxProp = $reflection->getProperty('maxConnections');
        $maxProp->setAccessible(true);

        $this->assertEquals(10, $maxProp->getValue($pool));
    }

    public function testPdoPoolDefaultMaxConnections()
    {
        $config = new Config();
        $config->set('database.mysql', []);
        $pool = new PdoPool($config);

        $reflection = new \ReflectionClass($pool);
        $maxProp = $reflection->getProperty('maxConnections');
        $maxProp->setAccessible(true);

        $this->assertEquals(10, $maxProp->getValue($pool));
    }

    public function testEloquentManagerBoot()
    {
        $pool = new PdoPool($this->config);
        $manager = new EloquentManager($this->config, $pool);

        // boot() 不应抛异常（懒加载，不触发真实连接）
        $manager->boot();

        $this->assertNotNull($manager->getCapsule());
    }

    public function testEloquentManagerBootIsIdempotent()
    {
        $pool = new PdoPool($this->config);
        $manager = new EloquentManager($this->config, $pool);

        $manager->boot();
        $capsule1 = $manager->getCapsule();

        $manager->boot();
        $capsule2 = $manager->getCapsule();

        // 重复 boot 返回同一个 Capsule 实例
        $this->assertSame($capsule1, $capsule2);
    }

    public function testModelBaseClassExtendsEloquent()
    {
        $reflection = new \ReflectionClass(Model::class);

        $this->assertTrue($reflection->isAbstract());
        $this->assertEquals(
            \Illuminate\Database\Eloquent\Model::class,
            $reflection->getParentClass()->getName()
        );
    }

    public function testModelBaseClassDisablesTimestamps()
    {
        $reflection = new \ReflectionClass(Model::class);
        $prop = $reflection->getProperty('timestamps');
        $prop->setAccessible(true);

        // Model 是 abstract，用子类实例化
        $instance = new class extends Model
        {
        };
        $this->assertFalse($prop->getValue($instance));
    }
}
