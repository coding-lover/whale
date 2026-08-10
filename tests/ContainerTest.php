<?php

namespace Sikelan\Tests;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Container;

class ContainerTest extends TestCase
{
    public function testContainerSetAndGet()
    {
        $container = new Container();
        $container->set('test', 'value');

        $this->assertEquals('value', $container->get('test'));
    }

    public function testContainerHas()
    {
        $container = new Container();
        $container->set('test', 'value');

        $this->assertTrue($container->has('test'));
        $this->assertFalse($container->has('not_exist'));
    }

    public function testContainerFactory()
    {
        $container = new Container();
        $container->set('factory', function ($c) {
            return ['value' => 'from_factory'];
        });

        $result = $container->get('factory');
        $this->assertEquals(['value' => 'from_factory'], $result);
    }

    public function testContainerAutoWire()
    {
        $container = new Container();
        $obj = $container->get(TestClass::class);

        $this->assertInstanceOf(TestClass::class, $obj);
        $this->assertEquals('dependency_value', $obj->getDependency()->value);
    }
}
