<?php

namespace Sikelan\Tests;

class TestClass
{
    protected $dependency;

    public function __construct(TestDependency $dependency)
    {
        $this->dependency = $dependency;
    }

    public function getDependency()
    {
        return $this->dependency;
    }
}
