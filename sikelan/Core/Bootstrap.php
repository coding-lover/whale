<?php

namespace Sikelan\Core;

/**
 * 框架统一引导器（Bootstrap）
 *
 * 设计目标：
 *   项目存在多个入口文件（bin/sikelan、bin/start.php、tests/bootstrap.php、Framework::getInstance），
 *   每个入口都需要「加载 autoload → 加载 constants → 加载 common → 加载 app/common.php
 *   → 处理 xdebug → 兜底 env() 函数」这一套步骤。之前这些代码在各入口里散点复制，
 *   维护/改一处要改 N 个文件。
 *
 *   现统一收敛到 Bootstrap 类里对外提供 3 个语义化静态方法：
 *     - Bootstrap::cli(string $entryDir)      CLI 入口（bin/sikelan / bin/start.php）
 *     - Bootstrap::test(string $entryDir)     PHPUnit bootstrap（更严格 + env 兜底）
 *     - Bootstrap::core(string $baseDir)      框架内调用（只加载 core 层 + app common，避免 xdebug 打印）
 *
 *   ⭐ 以后新增入口文件 / 改变加载顺序，**只要改这一个类**，入口文件永远保持干净。
 *
 * @package Sikelan\Core
 */
class Bootstrap
{
    /** @var bool 已执行过 loadCore（避免重复 require）*/
    private static bool $coreLoaded = false;

    /** @var bool 已执行过 autoload（bin/sikelan 在 require Bootstrap 之前已经手动 autoload）*/
    private static bool $autoloadLoaded = false;

    /**
     * CLI 入口专用引导（bin/sikelan、bin/start.php 用）。
     *
     * 依次执行：autoload → core → xdebug 关闭（CLI 下 xdebug 会触发 Swoole 协程警告）。
     *
     * @param string $entryDir 入口文件所在目录，一般传 `__DIR__`。通过它定位 BASE_PATH = dirname(entryDir)。
     * @param bool   $warnXdebugCLI  true = 当 CLI + xdebug 开启时打印黄色提示（start.php 行为）；false = 静默关
     */
    public static function cli(string $entryDir, bool $warnXdebugCLI = true): void
    {
        self::autoload($entryDir);
        self::core($entryDir);
        self::disableXdebug($warnXdebugCLI);
    }

    /**
     * PHPUnit 测试引导专用（tests/bootstrap.php 用）。
     *
     * 比 cli() 多做了一步：兜底 env() 函数（保证 CI/phpunit 进程里没加载 common.php 也能跑 env()）。
     */
    public static function test(string $entryDir): void
    {
        self::autoload($entryDir);
        self::core($entryDir);
        self::ensureEnvFunction();
    }

    /**
     * 只加载核心层（constants + common + app common.php），不做 autoload、不碰 xdebug。
     *
     * 供 Framework::getInstance() 内部调用，避免重复 require（入口文件已经做过 cli() 时安全 noop）。
     *
     * @param string $baseDirOrEntryDir 可传 BASE_PATH 或 __DIR__（入口目录）；内部会向上找 BASE_PATH 定位
     */
    public static function core(string $baseDirOrEntryDir): void
    {
        if (self::$coreLoaded) {
            return;
        }
        $basePath = self::resolveBasePath($baseDirOrEntryDir);
        $corePath = $basePath . '/sikelan/Core';

        // ---- constants.php（BASE_PATH/APP_PATH 等在这里定义，必须先加载）----
        // constants.php 内部有 defined('XXX') || define('XXX') 守卫，本身可重复安全；
        // 这里再包一次 file_exists，避免未来重构目录时 fatal。
        $constantsFile = $corePath . '/constants.php';
        if (file_exists($constantsFile)) {
            require_once $constantsFile;
        }

        // ---- common.php（env()/全局工具函数，依赖 constants 里的 BASE_PATH）----
        $commonFile = $corePath . '/common.php';
        if (file_exists($commonFile)) {
            require_once $commonFile;
        }

        // ---- app/common.php（应用层快捷函数：container()/exchange()/config() 等）----
        $appCommonFile = $basePath . '/app/common.php';
        if (file_exists($appCommonFile)) {
            require_once $appCommonFile;
        }

        self::$coreLoaded = true;
    }

    /**
     * 加载 composer autoload。失败会给明确提示（给「没 composer install 的新人」友好提示）。
     */
    public static function autoload(string $entryDir): void
    {
        if (self::$autoloadLoaded) {
            return;
        }
        $basePath = self::resolveBasePath($entryDir);
        $autoload = $basePath . '/vendor/autoload.php';
        if (!file_exists($autoload)) {
            // 入口文件可能还没定义 ANSI 颜色常量；直接写 ANSI 裸码保持可读
            echo "\033[31mError: vendor/autoload.php not found.\033[0m\n";
            echo "Please run 'composer install' first.\n";
            exit(1);
        }
        require_once $autoload;
        self::$autoloadLoaded = true;
    }

    /**
     * 关闭 xdebug（Swoole 协程环境下 xdebug 会有兼容问题；CLI/HTTP 生产都应该关）。
     *
     * @param bool $warnCli true = CLI 模式时额外打印警告，提醒去 php.ini 里关彻底
     */
    public static function disableXdebug(bool $warnCli = true): void
    {
        if (!extension_loaded('xdebug')) {
            return;
        }
        // 直接用 ini_set，不用 @（被规则禁止；如果 ini_set 失败静默也没关系）
        ini_set('xdebug.mode', 'off');
        ini_set('xdebug.start_with_request', 'no');

        if ($warnCli && PHP_SAPI === 'cli') {
            echo "\033[33m⚠ Warning: Xdebug is loaded. It may cause issues with Swoole coroutines.\033[0m\n";
            echo "\033[33m  Please disable Xdebug in php.ini: set xdebug.mode=off\033[0m\n\n";
        }
    }

    /**
     * 兜底 env() 函数（仅用于 PHPUnit bootstrap 等场景：common.php 可能还没加载就被配置读 env()）。
     * 注意：common.php 里的同名函数自带 `!function_exists('env')` 守卫，二者兼容。
     */
    public static function ensureEnvFunction(): void
    {
        if (function_exists('env')) {
            return;
        }

        require_once __DIR__ . '/common.php';
    }

    /**
     * 重置状态（仅 PHPUnit 隔离测试用；生产代码永远不要调）。
     *
     * @internal
     */
    public static function resetState(): void
    {
        self::$coreLoaded = false;
        self::$autoloadLoaded = false;
    }

    // ------------------------------------------------------------------------
    //  内部工具
    // ------------------------------------------------------------------------

    /**
     * 解析项目根路径 BASE_PATH。
     * 支持 2 种调用：
     *   - 传 `__DIR__`（入口文件目录，形如 /path/to/project/bin 或 /path/to/project/tests）
     *   - 直接传 BASE_PATH（Framework 里常用）
     * 通过「往上找 vendor/autoload.php」兜底，避免在入口文件手拼相对路径出错。
     */
    private static function resolveBasePath(string $dirOrPath): string
    {
        // 如果路径直接就是 BASE_PATH（存在子目录 vendor/sikelan/app 三项中两项）直接返回
        $candidates = ['vendor', 'sikelan', 'app'];
        $hitCount = 0;
        foreach ($candidates as $c) {
            if (is_dir($dirOrPath . '/' . $c)) {
                $hitCount++;
            }
        }
        if ($hitCount >= 2) {
            return rtrim($dirOrPath, '/');
        }

        // 向上最多找 5 级目录（bin/ 和 tests/ 都是项目根的子目录，1 级就够；留冗余）
        $cur = $dirOrPath;
        for ($i = 0; $i < 5; $i++) {
            if (file_exists($cur . '/vendor/autoload.php') && is_dir($cur . '/sikelan')) {
                return $cur;
            }
            $parent = dirname($cur);
            if ($parent === $cur) {
                break;
            }
            $cur = $parent;
        }
        // 都找不到 → 退化：入口上一级（bin → project；tests → project）
        return dirname($dirOrPath);
    }
}
