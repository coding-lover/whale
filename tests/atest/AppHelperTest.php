<?php

namespace Sikelan\Tests\Atest;

use PHPUnit\Framework\TestCase;
use Sikelan\Framework;
use Sikelan\Http\Router;

/**
 * app() 全局辅助函数测试
 *
 * 验证 app() 不传参返回 Framework 单例，
 * 传类名/别名时从容器解析服务。
 */
class AppHelperTest extends TestCase
{
    protected function setUp(): void
    {
        // 重置 Framework 单例，避免测试间状态污染
        $reflection = new \ReflectionClass(Framework::class);
        $property = $reflection->getProperty('_instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    public function testAppWithoutArgReturnsFramework()
    {
        $this->assertInstanceOf(Framework::class, app());
    }

    public function testAppReturnsSameSingleton()
    {
        $this->assertSame(app(), app());
    }

    public function testAppResolvesServiceByClassName()
    {
        // Router 可被容器自动装配（无构造依赖或依赖可解析）
        $router = app(Router::class);

        $this->assertInstanceOf(Router::class, $router);
    }

    public function testAppResolvesServiceByAlias()
    {
        $container = app()->getContainer();
        $dummy = new \stdClass();
        $dummy->marker = 'alias_test';
        $container->set('my_dummy_alias', $dummy);

        $resolved = app('my_dummy_alias');

        $this->assertSame($dummy, $resolved);
        $this->assertSame('alias_test', $resolved->marker);
    }

    public function testAppResolvesFactoryService()
    {
        $container = app()->getContainer();
        $container->set('factory_svc', function () {
            $obj = new \stdClass();
            $obj->from = 'factory';
            return $obj;
        });

        $resolved = app('factory_svc');

        $this->assertSame('factory', $resolved->from);
    }

    public function testAppThrowsForUnknownService()
    {
        $this->expectException(\InvalidArgumentException::class);

        app('completely_nonexistent_service_xyz');
    }
}
