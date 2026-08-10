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
            $this->instances[$id] = $this->build($id);
        }

        return $this->instances[$id];
    }

    public function has($id)
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]) || class_exists($id);
    }

    public function set($id, $value)
    {
        if (is_callable($value)) {
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
