<?php

namespace Sikelan\Command;

interface CommandInterface
{
    public function commandName(): string;

    public function exec(array $args): ?string;

    public function help(array $args): ?string;

    public function desc(): string;
}
