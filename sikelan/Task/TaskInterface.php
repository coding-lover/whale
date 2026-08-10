<?php

namespace Sikelan\Task;

interface TaskInterface
{
    public function handle(array $args);
}
