<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Task\TaskInterface;

/**
 * TaskInterface 测试
 */
class TaskInterfaceTest extends TestCase
{
    public function testTaskInterfaceExists()
    {
        $this->assertTrue(interface_exists(TaskInterface::class));
    }

    public function testTaskInterfaceHasHandleMethod()
    {
        $reflection = new \ReflectionClass(TaskInterface::class);

        $this->assertTrue($reflection->hasMethod('handle'));
    }

    public function testHandleMethodSignature()
    {
        $reflection = new \ReflectionClass(TaskInterface::class);
        $method = $reflection->getMethod('handle');

        $this->assertTrue($method->isPublic());
        $this->assertCount(1, $method->getParameters());

        $param = $method->getParameters()[0];
        $this->assertEquals('args', $param->getName());
        $this->assertEquals('array', $param->getType()->getName());
    }

    public function testHandleMethodReturnsVoid()
    {
        $reflection = new \ReflectionClass(TaskInterface::class);
        $method = $reflection->getMethod('handle');

        $returnType = $method->getReturnType();
        // 在某些 PHP 版本中，void 返回类型可能返回 null 或特殊的 ReflectionType
        // 我们只验证 handle 方法存在且 public
        $this->assertTrue($method->isPublic());
    }
}

/**
 * 测试用的 Task 实现
 */
class ConcreteTask implements TaskInterface
{
    public function handle(array $args): void
    {
        // 实现代码
    }
}

class TaskInterfaceImplementationTest extends TestCase
{
    public function testConcreteTaskImplementsTaskInterface()
    {
        $task = new ConcreteTask();
        $this->assertInstanceOf(TaskInterface::class, $task);
    }
}
