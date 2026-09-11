<?php

/**
 * PHPUnit 测试引导文件。
 *
 * ⭐ 保持极简：所有「autoload / constants / common / env 兜底」统一走 Bootstrap::test()。
 * 不要在这里重复 require 核心文件——未来加新的全局工具，只改 sikelan/Core/Bootstrap.php 一处。
 */

use Sikelan\Core\Bootstrap;

require_once __DIR__ . '/../sikelan/Core/Bootstrap.php';
Bootstrap::test(__DIR__);
