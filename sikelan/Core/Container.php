<?php

namespace Sikelan\Core;

use Psr\Container\ContainerInterface;

class Container implements ContainerInterface
{
    protected $instances = [];
    protected $factories = [];

    public function get($id)
    {
        if (!$this->has($id)) {
            throw new \InvalidArgumentException("Service {$id} not found");
        }

        if (isset($this->factories[$id])) {
            return call_user_func($this->factories[$id], $this);
        }

        if (!isset($this->instances[$id])) {
            // 先构建（构造函数注入）并缓存实例，以打破属性间的循环依赖
            $this->instances[$id] = $this->build($id);
            // 再进行属性注入
            $this->injectProperties($this->instances[$id]);
        }

        return $this->instances[$id];
    }

    /**
     * 属性注入：自动解析未初始化的类型化成员变量
     *
     * 遍历实例的所有属性，对满足以下条件的属性从容器解析依赖并注入：
     *  - 非静态属性
     *  - 类型为类/接口（非内置类型）
     *  - 尚未初始化（构造函数未设置且无默认值）
     *
     * 已初始化的属性不会被覆盖，保证构造函数赋值和默认值优先。
     *
     * @param object $instance 待注入属性的实例
     */
    protected function injectProperties(object $instance): void
    {
        $reflection = new \ReflectionClass($instance);

        foreach ($reflection->getProperties() as $property) {
            // 跳过静态属性
            if ($property->isStatic()) {
                continue;
            }

            $type = $property->getType();

            // 只处理非内置的命名类型（类/接口），跳过无类型和标量类型
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            // 支持访问 private/protected 属性
            $property->setAccessible(true);

            // 已初始化的属性不覆盖（构造函数已设置或声明了默认值）
            if ($property->isInitialized($instance)) {
                continue;
            }

            // 从容器解析依赖并注入
            $property->setValue($instance, $this->get($type->getName()));
        }
    }

    public function has($id)
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]) || class_exists($id);
    }

    public function set($id, $value)
    {
        // 仅 Closure 或实现 __invoke 的对象视为工厂；
        // 字符串（哪怕恰好是函数名）一律按实例存储，避免与全局函数名冲突。
        if ($value instanceof \Closure || (is_object($value) && method_exists($value, '__invoke'))) {
            $this->factories[$id] = $value;
        } else {
            $this->instances[$id] = $value;
        }
        return $this;
    }

    protected function build($id)
    {
        $reflector = new \ReflectionClass($id);

        if (!$reflector->isInstantiable()) {
            throw new \InvalidArgumentException("Class {$id} is not instantiable");
        }

        $constructor = $reflector->getConstructor();

        if (is_null($constructor)) {
            return new $id();
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = $this->get($type->getName());
            } else {
                $dependencies[] = $parameter->getDefaultValue();
            }
        }

        return $reflector->newInstanceArgs($dependencies);
    }
}
