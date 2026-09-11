<?php

namespace Sikelan\Hook;

use Sikelan\Core\Container;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use Sikelan\Server\Server;

/**
 * Hook 抽象基类
 * 
 * 提供默认的空实现，用户继承此类后只需重写需要的方法即可。
 * 
 * 用法示例：
 * 
 * // config/app.php
 * return [
 *     'hook' => \App\Hooks\AppHook::class,
 *     // ...
 * ];
 * 
 * // app/Hooks/AppHook.php
 * class AppHook extends \Sikelan\Hook\AbstractHook
 * {
 *     public function registerEvents(): array
 *     {
 *         return [
 *             'request' => function ($request, $response) {
 *                 // 自定义请求处理
 *             }
 *         ];
 *     }
 * 
 *     public function registerProcesses(): array
 *     {
 *         return [
 *             [
 *                 'name' => 'custom_process',
 *                 'callback' => function ($worker) {
 *                     // 自定义进程逻辑
 *                 }
 *             ]
 *         ];
 *     }
 * }
 */
abstract class AbstractHook implements HookInterface
{
    protected Container $container;

    protected Config $config;

    protected Logger $logger;

    public function __construct(Container $container, Config $config, Logger $logger)
    {
        $this->container = $container;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * 框架初始化阶段钩子（默认空实现）
     */
    public function onInitialize(Server $server): void
    {
        // 默认不做事，由子类按需重写
    }

    /**
     * 服务器启动前钩子（默认空实现）
     */
    public function onServerStart(Server $server): void
    {
        // 默认不做事，由子类按需重写
    }

    /**
     * 注册自定义事件回调（默认返回空数组，使用系统默认事件回调）
     */
    public function registerEvents(): array
    {
        return [];
    }

    /**
     * 注册自定义进程（默认返回空数组，不添加任何自定义进程）
     */
    public function registerProcesses(): array
    {
        return [];
    }
}
