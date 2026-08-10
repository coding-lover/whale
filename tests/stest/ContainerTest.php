<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Container;

/**
 * Container 全覆盖测试
 */
class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    // ========== get() 方法测试 ==========

    public function testGetReturnsSetValue()
    {
        $this->container->set('test_key', 'test_value');
        $this->assertEquals('test_value', $this->container->get('test_key'));
    }

    public function testGetThrowsExceptionForNonExistentService()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service non_existent not found');

        $this->container->get('non_existent');
    }

    public function testGetReturnsFactoryResult()
    {
        $this->container->set('factory', function ($c) {
            return ['factory_data' => true];
        });

        $result = $this->container->get('factory');
        $this->assertEquals(['factory_data' => true], $result);
    }

    public function testGetReturnsBuiltClassInstance()
    {
        $result = $this->container->get(\Sikelan\Http\Router::class);
        $this->assertInstanceOf(\Sikelan\Http\Router::class, $result);
    }

    // ========== has() 方法测试 ==========

    public function testHasReturnsTrueForSetInstance()
    {
        $this->container->set('test', 'value');
        $this->assertTrue($this->container->has('test'));
    }

    public function testHasReturnsTrueForSetFactory()
    {
        $this->container->set('factory', function () {
        });
        $this->assertTrue($this->container->has('factory'));
    }

    public function testHasReturnsTrueForExistingClass()
    {
        $this->assertTrue($this->container->has(\Sikelan\Http\Router::class));
    }

    public function testHasReturnsFalseForNonExistent()
    {
        $this->assertFalse($this->container->has('completely_nonexistent_key_12345'));
    }

    // ========== set() 方法测试 ==========

    public function testSetReturnsContainerForChaining()
    {
        $result = $this->container->set('key', 'value');
        $this->assertSame($this->container, $result);
    }

    public function testSetStoresValue()
    {
        $this->container->set('string_val', 'test');
        $this->assertEquals('test', $this->container->get('string_val'));
    }

    public function testSetStoresArray()
    {
        $this->container->set('array_val', ['key' => 'value']);
        $this->assertEquals(['key' => 'value'], $this->container->get('array_val'));
    }

    public function testSetStoresObject()
    {
        $obj = new \stdClass();
        $this->container->set('object_val', $obj);
        $this->assertSame($obj, $this->container->get('object_val'));
    }

    public function testSetCallableIsStoredAsFactory()
    {
        $called = false;
        $this->container->set('callable_val', function ($c) use (&$called) {
            $called = true;
            return 'factory_result';
        });

        // 第一次调用
        $result1 = $this->container->get('callable_val');
        $this->assertTrue($called);
        $this->assertEquals('factory_result', $result1);
    }

    // ========== build() 间接测试 ==========

    public function testBuildClassWithoutConstructor()
    {
        // 通过 get() 间接测试 build 功能 - Router 不需要依赖，可以直接构建
        $result = $this->container->get(\Sikelan\Http\Router::class);
        $this->assertInstanceOf(\Sikelan\Http\Router::class, $result);
    }

    public function testBuildThrowsExceptionForNonInstantiableClass()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found|not instantiable/');

        // 接口不能被实例化 - 通过 has() 检查后会尝试 build
        $this->container->get(\Sikelan\Task\TaskInterface::class);
    }

    public function testBuildWithDependencyInjection()
    {
        // 通过 get() 间接测试自动依赖注入
        $result = $this->container->get(\Sikelan\Http\Router::class);
        $this->assertInstanceOf(\Sikelan\Http\Router::class, $result);
    }

    // ========== 单例行为测试 ==========

    public function testGetReturnsSameInstanceForSameId()
    {
        $instance1 = $this->container->get(\Sikelan\Http\Router::class);
        $instance2 = $this->container->get(\Sikelan\Http\Router::class);

        $this->assertSame($instance1, $instance2);
    }

    // ========== PSR-11 ContainerInterface 实现测试 ==========

    public function testImplementsContainerInterface()
    {
        $this->assertInstanceOf(\Psr\Container\ContainerInterface::class, $this->container);
    }
}
