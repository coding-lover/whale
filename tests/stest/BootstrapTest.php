<?php

namespace Sikelan\Tests\stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Bootstrap;

/**
 * Bootstrap：统一引导加载器单测
 *
 * 注意：本测试只验证「加载过程的副作用」（常量/函数被定义），不验证
 * 真实入口文件。入口文件 CLI 行为已在手动 smoke test（php bin/sikelan）里验证。
 *
 * 位置：tests/stest/ （因为 Bootstrap 属于 sikelan/Core，框架核心层）
 *
 * @package Sikelan\Tests\stest
 */
class BootstrapTest extends TestCase
{
    /**
     * 由于 tests/bootstrap.php 里已经调用过 Bootstrap::test()，
     * 本测试运行时常量和全局函数都应该就位，这里做存在性断言。
     * （如果未来删掉 Bootstrap，换成别的方式，本测试会第一时间失败 → 提醒回归。）
     */
    public function testCoreConstantsAreDefinedAfterBootstrap(): void
    {
        $this->assertTrue(defined('BASE_PATH'), 'BASE_PATH 常量必须已定义');
        $this->assertTrue(defined('APP_PATH'), 'APP_PATH 常量必须已定义');
        $this->assertTrue(defined('CONFIG_PATH'), 'CONFIG_PATH 常量必须已定义');
        $this->assertTrue(defined('RUNTIME_PATH'), 'RUNTIME_PATH 常量必须已定义');
        $this->assertTrue(defined('LOG_PATH'), 'LOG_PATH 常量必须已定义');
        $this->assertTrue(defined('FRAMEWORK_PATH'), 'FRAMEWORK_PATH 常量必须已定义');
    }

    /**
     * 路径约定校验：RUNTIME_PATH 必须在 APP_PATH 下（即 app/runtime）。
     * 对应规则：global-style.md §12「运行时静态数据目录约定」。
     */
    public function testRuntimePathPointsInsideAppDirectory(): void
    {
        $this->assertStringStartsWith(
            APP_PATH . DIRECTORY_SEPARATOR . 'runtime',
            RUNTIME_PATH,
            'RUNTIME_PATH 必须指向 APP_PATH/runtime（全局规则 §12）'
        );
    }

    /**
     * 公共函数 env()（来自 sikelan/Core/common.php）必须已加载。
     */
    public function testCoreCommonFunctionsAreLoaded(): void
    {
        $this->assertTrue(function_exists('env'), 'env() 函数（common.php）必须已加载');
    }

    /**
     * 应用层快捷函数（app/common.php）必须已加载——它们是业务层每天会用的 container()/config()/exchange()。
     * （如果 bootstrap 漏加载 app/common.php，所有 Controller 立即 fatal。）
     */
    public function testAppCommonFunctionsAreLoaded(): void
    {
        $this->assertTrue(function_exists('config'), 'config() 函数（app/common.php）必须已加载');
        $this->assertTrue(function_exists('container'), 'container() 函数（app/common.php）必须已加载');
        $this->assertTrue(function_exists('exchange'), 'exchange() 函数（app/common.php）必须已加载');
        $this->assertTrue(function_exists('exchange_manager'), 'exchange_manager() 函数（app/common.php）必须已加载');
    }

    /**
     * Bootstrap::core() 允许重复调用：第二次必须 noop（不会重复 require 导致 warning / 常量 redefine fatal）。
     *
     * 该特性保证 Framework::getInstance() 重复调用、或入口 + Framework 双重调用都安全。
     */
    public function testCoreIsIdempotentAfterMultipleCalls(): void
    {
        // 用一个独立路径（和 BASE_PATH 相同，避免触发路径解析分支），再连调 2 次
        $before = error_get_last();
        Bootstrap::core(BASE_PATH);
        Bootstrap::core(BASE_PATH);
        $after = error_get_last();

        // 不允许产生 E_WARNING（require_once 没问题；但 require 会炸 → 本用例就是用来防改错）
        $this->assertSame($before, $after, 'Bootstrap::core() 多次调用不应产生新警告/错误');

        // 常量值不变（防 2 次 define fatal）
        $this->assertSame(BASE_PATH, dirname(APP_PATH), 'BASE_PATH 与 APP_PATH 相对关系稳定');
    }
}
